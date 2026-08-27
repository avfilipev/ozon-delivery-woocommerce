<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

/**
 * Страница каталога ПВЗ из delivery-point/list.
 *
 * Метод возвращает только идентификаторы и списки методов доставки; адреса,
 * координаты и график добираются отдельно через delivery-point/info.
 *
 * @see docs/API.md, метод delivery-point/list
 */
final class CatalogPage {

	/**
	 * @param array<int, int[]> $methods_by_id delivery_point_id => shipment_method_ids.
	 */
	public function __construct(
		private readonly array $methods_by_id,
		public readonly ?string $next_cursor = null
	) {
	}

	/**
	 * @param array<string, mixed> $response Ответ delivery-point/list.
	 */
	public static function from_api( array $response ): self {
		$methods_by_id = array();

		$points = isset( $response['delivery_points'] ) && is_array( $response['delivery_points'] )
			? $response['delivery_points']
			: array();

		foreach ( $points as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['delivery_point_id'] ) ) {
				continue;
			}

			$ids = isset( $point['shipment_method_ids'] ) && is_array( $point['shipment_method_ids'] )
				? array_values( array_map( 'intval', $point['shipment_method_ids'] ) )
				: array();

			$methods_by_id[ (int) $point['delivery_point_id'] ] = $ids;
		}

		$cursor = isset( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '';

		return new self( $methods_by_id, '' === $cursor ? null : $cursor );
	}

	/**
	 * @return int[]
	 */
	public function ids(): array {
		return array_keys( $this->methods_by_id );
	}

	/**
	 * @return int[]
	 */
	public function shipment_method_ids_for( int $delivery_point_id ): array {
		return $this->methods_by_id[ $delivery_point_id ] ?? array();
	}

	public function is_empty(): bool {
		return array() === $this->methods_by_id;
	}

	/**
	 * Курсора больше нет — обход каталога закончен.
	 */
	public function is_last(): bool {
		return null === $this->next_cursor;
	}
}
