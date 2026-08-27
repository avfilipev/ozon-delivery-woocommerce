<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\AuthException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Клиент Ozon Delivery API: адрес, авторизация, JSON, режим dry-run.
 *
 * Разбором results[].error занимаются вызывающие эндпоинты: у order/checkout,
 * order/create, delivery/location и delivery-point/check-availability ошибки
 * приходят внутри 200-го ответа поэлементно, и что с ними делать — решение
 * доменного слоя, а не транспортного.
 *
 * @see docs/API.md
 */
final class Client {

	private const BASE_URL = 'https://api-delivery.ozon.ru';

	/**
	 * Методы, создающие реальные сущности и списывающие деньги. В dry-run
	 * не отправляются.
	 *
	 * @var string[]
	 */
	private const WRITE_METHODS = array(
		'/v1/order/create',
		'/v1/posting/approve',
		'/v1/posting/label',
		'/v1/posting/cancel',
		// Сброс обесценивает уже напечатанный штрихкод получения возвратов.
		'/v1/return/reset_barcode',
	);

	/**
	 * Повтор после сетевого сбоя может изменить состояние второй раз:
	 * идемпотентность здесь ничем не гарантирована. order/create в список не
	 * входит — его защищает заголовок Idempotency-Key.
	 *
	 * @var string[]
	 */
	private const NON_IDEMPOTENT_METHODS = array(
		'/v1/posting/approve',
		'/v1/posting/label',
		'/v1/posting/cancel',
		'/v1/return/reset_barcode',
	);

	private const ORDER_CREATE = '/v1/order/create';

	public function __construct(
		private readonly Transport $transport,
		private readonly TokenStore $tokens,
		private readonly Logger $logger,
		private readonly bool $dry_run = false
	) {
	}

	/**
	 * @param array<string, mixed> $payload Тело запроса.
	 * @param array<string, string> $headers Заголовки поверх умолчаний.
	 *
	 * @return array<string, mixed> Разобранный JSON-ответ.
	 *
	 * @throws DryRunException Метод на запись при включённом dry-run.
	 * @throws AuthException   Токен получить или обновить не удалось.
	 * @throws ApiException    Ozon ответил кодом ошибки либо ответ не разобрать.
	 */
	public function post( string $path, array $payload, array $headers = array() ): array {
		$this->guard_idempotency_key( $path, $headers );
		$this->guard_dry_run( $path, $payload );

		$body = (string) wp_json_encode( $payload );

		$response = $this->send( $path, $body, $headers );

		// 401 отвергает запрос до обработки, поэтому обновить токен и повторить
		// безопасно даже для методов на запись.
		if ( 401 === $response->status ) {
			$this->tokens->forget();

			$response = $this->send( $path, $body, $headers );

			if ( 401 === $response->status ) {
				throw new AuthException(
					sprintf(
						'Ozon отклонил токен даже после обновления. trace-id: %s',
						$response->trace_id
					)
				);
			}
		}

		if ( $response->status < 200 || $response->status > 299 ) {
			throw new ApiException( $this->error_message( $response ) );
		}

		return $this->decode( $response );
	}

	/**
	 * Запрос без разбора JSON: нужен там, где Ozon отдаёт файл, а не JSON —
	 * например этикетка posting/label.
	 *
	 * @param array<string, mixed>  $payload
	 * @param array<string, string> $headers
	 *
	 * @throws DryRunException Метод на запись при включённом dry-run.
	 * @throws AuthException   Токен получить или обновить не удалось.
	 * @throws ApiException    Ozon ответил кодом ошибки.
	 */
	public function post_raw( string $path, array $payload, array $headers = array() ): Response {
		$this->guard_idempotency_key( $path, $headers );
		$this->guard_dry_run( $path, $payload );

		$body = (string) wp_json_encode( $payload );

		$response = $this->send( $path, $body, $headers );

		if ( 401 === $response->status ) {
			$this->tokens->forget();

			$response = $this->send( $path, $body, $headers );
		}

		if ( $response->status < 200 || $response->status > 299 ) {
			throw new ApiException( $this->error_message( $response ) );
		}

		return $response;
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function send( string $path, string $body, array $headers ): Response {
		$headers['Authorization'] = 'Bearer ' . $this->tokens->token();

		return $this->transport->post(
			$this->url( $path ),
			$body,
			$headers,
			! in_array( $path, self::NON_IDEMPOTENT_METHODS, true )
		);
	}

	private function url( string $path ): string {
		/**
		 * Базовый адрес API. Песочницы у Ozon нет, но фильтр нужен для
		 * подмены хоста в интеграционных прогонах.
		 *
		 * @param string $base_url Базовый адрес.
		 */
		$base = (string) apply_filters( 'ozon_delivery_api_base_url', self::BASE_URL );

		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Правило 4: order/create только с заголовком Idempotency-Key.
	 *
	 * @param array<string, string> $headers
	 *
	 * @throws ApiException
	 */
	private function guard_idempotency_key( string $path, array $headers ): void {
		if ( self::ORDER_CREATE !== $path ) {
			return;
		}

		if ( '' === ( $headers['Idempotency-Key'] ?? '' ) ) {
			throw new ApiException(
				'order/create нельзя вызывать без заголовка Idempotency-Key: повтор создаст второе отправление.'
			);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws DryRunException
	 */
	private function guard_dry_run( string $path, array $payload ): void {
		if ( ! $this->dry_run || ! in_array( $path, self::WRITE_METHODS, true ) ) {
			return;
		}

		$this->logger->log(
			'info',
			'Dry-run: запрос на запись не отправлен',
			array(
				'path'    => $path,
				'payload' => $payload,
			)
		);

		throw new DryRunException(
			sprintf( 'Включён режим dry-run: запрос %s не отправлен в Ozon.', $path )
		);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	private function decode( Response $response ): array {
		// posting/approve и posting/cancel по спецификации отвечают «200 без
		// тела» — это штатный успех, а не нечитаемый JSON.
		if ( '' === trim( $response->body ) ) {
			return array();
		}

		$decoded = json_decode( $response->body, true );

		if ( ! is_array( $decoded ) ) {
			throw new ApiException(
				sprintf( 'Ответ Ozon не разобрать как JSON. trace-id: %s', $response->trace_id )
			);
		}

		return $decoded;
	}

	/**
	 * Формат ошибки HTTP из docs/API.md: error.code, error.message.
	 */
	private function error_message( Response $response ): string {
		$decoded = json_decode( $response->body, true );
		$error   = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] )
			? $decoded['error']
			: array();

		$code    = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '';
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';

		$this->logger->log(
			'error',
			'Ozon вернул ошибку',
			array(
				'status'        => $response->status,
				'code'          => $code,
				'x-o3-trace-id' => $response->trace_id,
			)
		);

		return sprintf(
			'Ozon вернул ошибку (HTTP %d%s)%s trace-id: %s',
			$response->status,
			'' !== $code ? ', код ' . $code : '',
			'' !== $message ? ': ' . $message . '.' : '.',
			$response->trace_id
		);
	}
}
