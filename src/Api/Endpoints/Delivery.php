<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Endpoints;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Shipping\DeliveryEstimate;

/**
 * Проверка доступности доставки и предварительные сроки.
 *
 * @see docs/API.md, раздел «Доступность и сроки»
 */
final class Delivery {

	private const CHECK_CLIENT_PATH = '/v1/delivery/check-client';

	private const LOCATION_PATH = '/v1/delivery/location';

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * Доставка Ozon доступна только покупателям, зарегистрированным на Ozon.
	 *
	 * Ответ без поля трактуется как отказ: обещать доставку, которой может не
	 * быть, хуже, чем не показать метод.
	 */
	public function can_deliver_to( string $phone_number ): bool {
		$phone = trim( $phone_number );

		if ( '' === $phone ) {
			return false;
		}

		$response = $this->client->post( self::CHECK_CLIENT_PATH, array( 'phone_number' => $phone ) );

		return true === ( $response['can_be_delivered'] ?? false );
	}

	/**
	 * Предварительный срок по координатам покупателя, ещё до выбора товара.
	 *
	 * @param int[] $shipment_method_ids
	 *
	 * @return array<int, DeliveryEstimate> Ключ — shipment_method_id.
	 */
	public function location(
		float $latitude,
		float $longitude,
		array $shipment_method_ids,
		?string $cutoff_at = null
	): array {
		$ids = array_values( array_unique( array_map( 'intval', $shipment_method_ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$methods = array();

		foreach ( $ids as $id ) {
			$method = array( 'shipment_method_id' => $id );

			if ( null !== $cutoff_at && '' !== $cutoff_at ) {
				$method['cutoff_at'] = $cutoff_at;
			}

			$methods[] = $method;
		}

		$response = $this->client->post(
			self::LOCATION_PATH,
			array(
				'coordinates'      => array(
					'latitude'  => $latitude,
					'longitude' => $longitude,
				),
				'shipment_methods' => $methods,
			)
		);

		$results = isset( $response['results'] ) && is_array( $response['results'] )
			? $response['results']
			: array();

		$estimates = array();

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$estimate = DeliveryEstimate::from_result( $result );

			$estimates[ $estimate->shipment_method_id ] = $estimate;
		}

		return $estimates;
	}
}
