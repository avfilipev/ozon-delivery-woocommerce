<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use InvalidArgumentException;

/**
 * Куда едет отправление: в пункт выдачи или курьером по координатам.
 *
 * В запросах Ozon это блок `delivery`, где ровно одна из двух веток.
 *
 * @see docs/API.md, методы order/checkout и order/create
 */
final class Destination {

	private function __construct(
		public readonly ?int $delivery_point_id = null,
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null
	) {
	}

	public static function point( int $delivery_point_id ): self {
		if ( $delivery_point_id <= 0 ) {
			throw new InvalidArgumentException( 'Идентификатор пункта выдачи должен быть положительным.' );
		}

		return new self( $delivery_point_id );
	}

	public static function courier( float $latitude, float $longitude ): self {
		if ( $latitude < -90.0 || $latitude > 90.0 ) {
			throw new InvalidArgumentException(
				sprintf( 'Широта вне допустимого диапазона: %s.', (string) $latitude )
			);
		}

		if ( $longitude < -180.0 || $longitude > 180.0 ) {
			throw new InvalidArgumentException(
				sprintf( 'Долгота вне допустимого диапазона: %s.', (string) $longitude )
			);
		}

		return new self( null, $latitude, $longitude );
	}

	public function is_pickup_point(): bool {
		return null !== $this->delivery_point_id;
	}

	/**
	 * @return array<string, mixed> Блок delivery для запроса.
	 */
	public function to_array(): array {
		if ( $this->is_pickup_point() ) {
			return array( 'delivery_point' => array( 'delivery_point_id' => $this->delivery_point_id ) );
		}

		return array(
			'courier' => array(
				'coordinates' => array(
					'latitude'  => $this->latitude,
					'longitude' => $this->longitude,
				),
			),
		);
	}
}
