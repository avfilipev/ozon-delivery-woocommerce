<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

use Closure;
use Spoki\OzonDelivery\Api\Exception\RateLimitException;
use Spoki\OzonDelivery\Api\Exception\TransportException;
use Spoki\OzonDelivery\Support\Logger;
use WP_Error;

/**
 * Единственная точка выхода в сеть для всего плагина.
 *
 * Берёт на себя то, чего не умеет wp_remote_post():
 *
 * - редиректы 302/307 анти-DDoS модуля testcookie с сохранением метода POST и
 *   тела запроса (wp_remote_post на 302 через cURL превращает POST в GET и
 *   теряет тело, поэтому redirection = 0 и ручной цикл);
 * - хранение и подстановку cookie, включая смену значения в середине сессии;
 * - ретраи с экспоненциальной паузой на 429, 5xx и сетевых сбоях, с учётом
 *   заголовка Retry-After и с возможностью отключить их для неидемпотентных
 *   вызовов вроде order/create;
 * - логирование x-o3-trace-id у каждого ответа.
 *
 * Тело ответа не разбирается и не логируется: в нём лежат и токены, и
 * персональные данные покупателя.
 *
 * @see docs/API.md, разделы «Защита testcookie» и «Служебное»
 */
final class Transport {

	private const MAX_REDIRECTS = 5;

	private const MAX_RETRIES = 2;

	private const TIMEOUT = 30;

	private const TRACE_HEADER = 'x-o3-trace-id';

	/**
	 * @var Closure(int): void
	 */
	private Closure $sleeper;

	/**
	 * @param Closure(int): void|null $sleeper Пауза между попытками; своя нужна в тестах.
	 */
	public function __construct(
		private readonly CookieJar $cookies,
		private readonly Logger $logger,
		?Closure $sleeper = null
	) {
		$this->sleeper = $sleeper ?? static function ( int $seconds ): void {
			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		};
	}

	/**
	 * @param array<string, string> $headers          Заголовки поверх умолчаний.
	 * @param bool                  $retry_on_failure false для неидемпотентных вызовов.
	 *
	 * @throws RateLimitException Ozon ответил 429 и попытки закончились.
	 * @throws TransportException Ответ получить не удалось.
	 */
	public function post( string $url, string $body, array $headers = array(), bool $retry_on_failure = true ): Response {
		$max_redirects = (int) apply_filters( 'ozon_delivery_max_redirects', self::MAX_REDIRECTS );
		$max_retries   = $retry_on_failure
			? (int) apply_filters( 'ozon_delivery_max_retries', self::MAX_RETRIES )
			: 0;

		$current_url = $url;
		$redirects   = 0;
		$retries     = 0;

		while ( true ) {
			$raw = wp_remote_post( $current_url, $this->args( $current_url, $body, $headers ) );

			if ( $raw instanceof WP_Error ) {
				if ( $retries < $max_retries ) {
					++$retries;
					$this->wait( $this->delay( $retries ), $current_url, 'сетевая ошибка' );
					continue;
				}

				$this->logger->log(
					'error',
					'Запрос к Ozon не удался',
					array(
						'url'   => $current_url,
						'error' => $raw->get_error_message(),
					)
				);

				throw new TransportException(
					sprintf( 'Запрос к Ozon не удался: %s', $raw->get_error_message() )
				);
			}

			$status   = (int) wp_remote_retrieve_response_code( $raw );
			$trace_id = (string) wp_remote_retrieve_header( $raw, self::TRACE_HEADER );

			$this->log_response( $current_url, $status, $trace_id, $headers );

			if ( 302 === $status || 307 === $status ) {
				$current_url = $this->follow( $raw, $current_url, $redirects, $max_redirects, $trace_id );
				++$redirects;
				continue;
			}

			if ( $this->is_retryable( $status ) && $retries < $max_retries ) {
				++$retries;
				$this->wait( $this->delay( $retries, $raw ), $current_url, sprintf( 'ответ %d', $status ) );
				continue;
			}

			if ( 429 === $status ) {
				throw new RateLimitException(
					sprintf( 'Ozon ограничил частоту запросов (429), попытки исчерпаны. trace-id: %s', $trace_id )
				);
			}

			return new Response(
				$status,
				(string) wp_remote_retrieve_body( $raw ),
				$trace_id,
				(string) wp_remote_retrieve_header( $raw, 'content-type' )
			);
		}
	}

	/**
	 * Разбирает редирект testcookie: запоминает cookie и возвращает адрес,
	 * по которому нужно повторить тот же POST с тем же телом.
	 *
	 * @param array<string, mixed>|WP_Error $raw
	 *
	 * @throws TransportException Редирект без Location или зациклился.
	 */
	private function follow( mixed $raw, string $url, int $redirects, int $max_redirects, string $trace_id ): string {
		$set_cookie = wp_remote_retrieve_header( $raw, 'set-cookie' );

		if ( array() !== $set_cookie && '' !== $set_cookie ) {
			$this->cookies->remember( $set_cookie );
		}

		$location = (string) wp_remote_retrieve_header( $raw, 'location' );

		if ( '' === $location ) {
			throw new TransportException(
				sprintf( 'Ozon вернул редирект без заголовка Location. trace-id: %s', $trace_id )
			);
		}

		if ( $redirects >= $max_redirects ) {
			$this->cookies->forget();

			throw new TransportException(
				sprintf( 'Зациклился редирект testcookie: больше %d переходов на %s', $max_redirects, $url )
			);
		}

		return $location;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function args( string $url, string $body, array $headers ): array {
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			$headers
		);

		$cookie = $this->cookies->header();

		if ( '' !== $cookie ) {
			$headers['Cookie'] = $cookie;
		}

		$args = array(
			'method'      => 'POST',
			'body'        => $body,
			'headers'     => $headers,
			'timeout'     => (int) apply_filters( 'ozon_delivery_request_timeout', self::TIMEOUT ),
			// Редиректы разбираются вручную: иначе WP потеряет тело POST на 302.
			'redirection' => 0,
			'sslverify'   => true,
		);

		/**
		 * Аргументы wp_remote_post перед отправкой.
		 *
		 * @param array<string, mixed> $args Аргументы запроса.
		 * @param string               $url  Адрес запроса.
		 */
		return (array) apply_filters( 'ozon_delivery_request_args', $args, $url );
	}

	/**
	 * 5xx и 429 повторяем, остальное отдаём вызывающему коду как есть.
	 */
	private function is_retryable( int $status ): bool {
		return 429 === $status || $status >= 500;
	}

	/**
	 * Экспоненциальная пауза: 1, 2, 4 секунды. Retry-After, если Ozon его
	 * прислал, важнее собственного расчёта.
	 *
	 * @param array<string, mixed>|WP_Error|null $raw
	 */
	private function delay( int $retry, mixed $raw = null ): int {
		$delay = 2 ** ( $retry - 1 );

		if ( is_array( $raw ) ) {
			$retry_after = (string) wp_remote_retrieve_header( $raw, 'retry-after' );

			if ( '' !== $retry_after && ctype_digit( $retry_after ) ) {
				$delay = (int) $retry_after;
			}
		}

		/**
		 * Пауза перед повторной попыткой, в секундах.
		 *
		 * @param int $delay Рассчитанная пауза.
		 * @param int $retry Номер попытки, начиная с единицы.
		 */
		return max( 0, (int) apply_filters( 'ozon_delivery_retry_delay', $delay, $retry ) );
	}

	private function wait( int $seconds, string $url, string $reason ): void {
		$this->logger->log(
			'warning',
			'Повтор запроса к Ozon',
			array(
				'url'    => $url,
				'reason' => $reason,
				'delay'  => $seconds,
			)
		);

		( $this->sleeper )( $seconds );
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function log_response( string $url, int $status, string $trace_id, array $headers ): void {
		$this->logger->log(
			$status >= 400 ? 'warning' : 'debug',
			'Ответ Ozon',
			array(
				'url'              => $url,
				'status'           => $status,
				self::TRACE_HEADER => $trace_id,
				// Logger маскирует Authorization и Cookie; тело не логируем никогда.
				'request_headers'  => $headers,
			)
		);
	}
}
