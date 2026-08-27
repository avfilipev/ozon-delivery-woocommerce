<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Endpoints;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Points\CatalogPage;
use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Points\PointAvailability;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;

/**
 * Методы пунктов выдачи: каталог, детали, проверка пригодности.
 *
 * @see docs/API.md, раздел «Пункты выдачи»
 */
final class DeliveryPoints {

	private const LIST_PATH = '/v1/delivery-point/list';

	private const INFO_PATH = '/v1/delivery-point/info';

	private const AVAILABILITY_PATH = '/v1/delivery-point/check-availability';

	/**
	 * Сколько точек запрашивать за один шаг обхода каталога.
	 */
	public const DEFAULT_PAGE_SIZE = 500;

	/**
	 * delivery-point/info принимает массив идентификаторов; пачку держим
	 * скромной, чтобы не упереться в размер запроса и в лимиты.
	 */
	public const INFO_BATCH_SIZE = 100;

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * Одна страница каталога. Пагинация курсорная: null — начать сначала.
	 */
	public function list_page( ?string $cursor = null, int $limit = self::DEFAULT_PAGE_SIZE ): CatalogPage {
		$pagination = array( 'limit' => $limit );

		if ( null !== $cursor && '' !== $cursor ) {
			$pagination['cursor'] = $cursor;
		}

		return CatalogPage::from_api(
			$this->client->post( self::LIST_PATH, array( 'pagination' => $pagination ) )
		);
	}

	/**
	 * Детали точек пачкой.
	 *
	 * @param int[]             $delivery_point_ids
	 * @param array<int, int[]> $methods_by_id      shipment_method_ids из каталога.
	 *
	 * @return DeliveryPoint[]
	 */
	public function info( array $delivery_point_ids, array $methods_by_id = array() ): array {
		$ids = array_values( array_unique( array_map( 'intval', $delivery_point_ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$response = $this->client->post( self::INFO_PATH, array( 'delivery_point_ids' => $ids ) );

		$raw = isset( $response['delivery_points'] ) && is_array( $response['delivery_points'] )
			? $response['delivery_points']
			: array();

		$points = array();

		foreach ( $raw as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}

			$id = (int) ( $point['delivery_point_id'] ?? 0 );

			$points[] = DeliveryPoint::from_api( $point, $methods_by_id[ $id ] ?? array() );
		}

		return $points;
	}

	/**
	 * Подойдут ли точки под конкретное отправление.
	 *
	 * В версии 1 отправление одно на заказ, поэтому request_id всегда 1:
	 * по нему Ozon сопоставляет результаты и ошибки в пределах запроса.
	 *
	 * @param int[] $delivery_point_ids
	 *
	 * @return array<int, PointAvailability> Ключ — delivery_point_id.
	 */
	public function check_availability(
		array $delivery_point_ids,
		int $shipment_method_id,
		Dimensions $dimensions,
		Money $declared_value,
		?string $cutoff_at = null
	): array {
		$ids = array_values( array_unique( array_map( 'intval', $delivery_point_ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$posting = array(
			'request_id'     => 1,
			'declared_value' => $declared_value->to_array(),
			'dimensions'     => $dimensions->to_array(),
		);

		if ( null !== $cutoff_at && '' !== $cutoff_at ) {
			$posting['cutoff_at'] = $cutoff_at;
		}

		/**
		 * Тело запроса проверки пригодности точек.
		 *
		 * @param array<string, mixed> $payload Тело запроса.
		 */
		$payload = (array) apply_filters(
			'ozon_delivery_check_availability_payload',
			array(
				'delivery_point_ids' => $ids,
				'shipment_method_id' => $shipment_method_id,
				'postings'           => array( $posting ),
			)
		);

		$response = $this->client->post( self::AVAILABILITY_PATH, $payload );

		$results = isset( $response['results'] ) && is_array( $response['results'] )
			? $response['results']
			: array();

		$availability = array();

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$parsed = PointAvailability::from_result( $result );

			$availability[ $parsed->delivery_point_id ] = $parsed;
		}

		return $availability;
	}
}
