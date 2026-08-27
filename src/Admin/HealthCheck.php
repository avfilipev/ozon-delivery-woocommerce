<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\AuthException;
use Spoki\OzonDelivery\Api\Exception\RateLimitException;
use Spoki\OzonDelivery\Api\Exception\TransportException;

/**
 * Проверка подключения к Ozon: ключи, токен, сеть и защита testcookie.
 *
 * Пробой служит delivery-point/list с лимитом 1 — метод ничего не создаёт и
 * поэтому безопасен даже на боевом контуре, где песочницы нет. Он же
 * проверяет весь путь целиком: OAuth, редиректы testcookie, разбор ответа.
 */
final class HealthCheck {

	private const PROBE_PATH = '/v1/delivery-point/list';

	public function __construct( private readonly Client $client ) {
	}

	public function run(): HealthCheckResult {
		try {
			$response = $this->client->post(
				self::PROBE_PATH,
				array( 'pagination' => array( 'limit' => 1 ) )
			);
		} catch ( AuthException $e ) {
			return HealthCheckResult::failed( $e->getMessage() );
		} catch ( RateLimitException $e ) {
			return HealthCheckResult::failed(
				sprintf( 'Ozon ограничивает частоту запросов. %s', $e->getMessage() )
			);
		} catch ( TransportException $e ) {
			return HealthCheckResult::failed(
				sprintf( 'Не удалось связаться с Ozon. %s', $e->getMessage() )
			);
		} catch ( ApiException $e ) {
			return HealthCheckResult::failed( $e->getMessage() );
		}

		$points = isset( $response['delivery_points'] ) && is_array( $response['delivery_points'] )
			? count( $response['delivery_points'] )
			: 0;

		return HealthCheckResult::ok(
			sprintf(
				'Подключение работает: авторизация прошла, каталог пунктов выдачи отвечает (получено записей: %d).',
				$points
			)
		);
	}
}
