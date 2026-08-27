<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

use Spoki\OzonDelivery\Api\Exception\AuthException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * OAuth-токен Ozon Доставки: получение, кэш и сброс.
 *
 * Токен кладётся в транзиент на срок из expires_in минус запас, чтобы не
 * получить 401 на границе истечения. При 401 вызывающий код обязан позвать
 * forget() — тогда следующий запрос сходит за новым токеном.
 *
 * ВНИМАНИЕ, ТРЕБУЕТ ПРОВЕРКИ НА ЖИВОМ ОТВЕТЕ. docs/API.md описывает только
 * параметры запроса (client_id, client_secret, grant_type, scope) и не
 * описывает схему ответа, а полной спеки в репозитории сейчас нет. Разбор
 * ответа и form-urlencoded тело сделаны по RFC 6749 (§4.4 и §5.1), то есть
 * по тому стандарту, который docs/API.md называет прямо. Как только появится
 * живой ответ — записать его в tests/Fixtures/ и переписать тест против
 * фикстуры, как требует правило 11.
 *
 * @see docs/API.md, раздел «Подключение»
 */
final class TokenStore {

	public const TRANSIENT = 'ozon_delivery_access_token';

	/**
	 * Запас до истечения: токен обновляется заранее, а не в момент отказа.
	 */
	public const EXPIRY_MARGIN = 60;

	/**
	 * Если Ozon не прислал expires_in — кэшируем ненадолго, а не навсегда.
	 */
	public const FALLBACK_TTL = 300;

	private const ENDPOINT = 'https://xapi.ozon.ru/oauth/token';

	public function __construct(
		private readonly Transport $transport,
		private readonly Credentials $credentials,
		private readonly Logger $logger
	) {
	}

	/**
	 * Действующий access_token: из кэша либо свежий.
	 *
	 * @throws AuthException Токен получить не удалось.
	 */
	public function token(): string {
		$cached = get_transient( self::TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->fetch();
	}

	/**
	 * Сбрасывает кэш токена. Вызывается при 401 и при смене ключей в настройках.
	 */
	public function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @throws AuthException
	 */
	private function fetch(): string {
		if ( ! $this->credentials->are_complete() ) {
			throw new AuthException(
				'Не заданы client_id и client_secret Ozon Доставки: заполните их в настройках WooCommerce.'
			);
		}

		$response = $this->transport->post( self::ENDPOINT, $this->request_body(), array( 'Content-Type' => 'application/x-www-form-urlencoded' ) );

		if ( 200 !== $response->status ) {
			$this->logger->log(
				'error',
				'Ozon не выдал токен',
				array(
					'status'        => $response->status,
					'x-o3-trace-id' => $response->trace_id,
				)
			);

			throw new AuthException(
				sprintf(
					'Ozon отклонил ключи доступа (HTTP %d). trace-id: %s',
					$response->status,
					$response->trace_id
				)
			);
		}

		$payload = json_decode( $response->body, true );

		if ( ! is_array( $payload ) ) {
			throw new AuthException(
				sprintf( 'Ответ точки выдачи токена не разобрать. trace-id: %s', $response->trace_id )
			);
		}

		$token = $payload['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			throw new AuthException(
				sprintf( 'В ответе Ozon нет access_token. trace-id: %s', $response->trace_id )
			);
		}

		set_transient( self::TRANSIENT, $token, $this->ttl( $payload ) );

		$this->logger->log(
			'info',
			'Получен токен Ozon',
			array( 'x-o3-trace-id' => $response->trace_id )
		);

		return $token;
	}

	private function request_body(): string {
		$params = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $this->credentials->client_id,
			'client_secret' => $this->credentials->client_secret,
		);

		if ( '' !== $this->credentials->scope ) {
			$params['scope'] = $this->credentials->scope;
		}

		/**
		 * Параметры запроса токена перед кодированием.
		 *
		 * @param array<string, string> $params Параметры client_credentials.
		 */
		$params = (array) apply_filters( 'ozon_delivery_token_request_params', $params );

		return http_build_query( $params );
	}

	/**
	 * Срок жизни кэша: expires_in минус запас, но всегда больше нуля.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function ttl( array $payload ): int {
		$expires_in = $payload['expires_in'] ?? null;

		if ( ! is_numeric( $expires_in ) ) {
			return self::FALLBACK_TTL;
		}

		return max( 1, (int) $expires_in - self::EXPIRY_MARGIN );
	}
}
