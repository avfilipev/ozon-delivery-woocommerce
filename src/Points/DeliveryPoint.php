<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;

/**
 * Пункт выдачи Ozon.
 *
 * Собирается из ответа delivery-point/info; `shipment_method_ids` приходит
 * отдельно, из delivery-point/list — там это единственное, кроме id, что
 * вообще возвращается.
 *
 * @see docs/API.md, методы delivery-point/list и delivery-point/info
 */
final class DeliveryPoint {

	/**
	 * @param int[]                $shipment_method_ids
	 * @param array<int, mixed>    $schedule График работы как его отдаёт Ozon.
	 */
	public function __construct(
		public readonly int $delivery_point_id,
		public readonly string $name,
		public readonly string $full_address,
		public readonly string $city = '',
		public readonly string $delivery_point_number = '',
		public readonly string $type = '',
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null,
		public readonly bool $is_active = false,
		public readonly bool $is_bulky = false,
		public readonly ?int $storage_period_days = null,
		public readonly ?int $fitting_rooms_count = null,
		public readonly array $shipment_method_ids = array(),
		public readonly array $schedule = array(),
		public readonly ?Restrictions $restrictions = null
	) {
	}

	/**
	 * @param array<string, mixed> $data                Точка из ответа Ozon.
	 * @param int[]                $shipment_method_ids Методы доставки из delivery-point/list.
	 */
	public static function from_api( array $data, array $shipment_method_ids = array() ): self {
		$coordinates = isset( $data['coordinates'] ) && is_array( $data['coordinates'] )
			? $data['coordinates']
			: array();

		$address = isset( $data['full_address'] ) ? (string) $data['full_address'] : '';

		return new self(
			(int) ( $data['delivery_point_id'] ?? 0 ),
			isset( $data['name'] ) ? (string) $data['name'] : '',
			$address,
			self::city_from_address( $address ),
			isset( $data['delivery_point_number'] ) ? (string) $data['delivery_point_number'] : '',
			isset( $data['type'] ) ? (string) $data['type'] : '',
			isset( $coordinates['latitude'] ) && is_numeric( $coordinates['latitude'] )
				? (float) $coordinates['latitude']
				: null,
			isset( $coordinates['longitude'] ) && is_numeric( $coordinates['longitude'] )
				? (float) $coordinates['longitude']
				: null,
			// Признака нет — считаем точку нерабочей: лучше скрыть, чем отправить
			// заказ в закрытый ПВЗ.
			! empty( $data['is_active'] ),
			! empty( $data['is_bulky'] ),
			isset( $data['storage_period_days'] ) && is_numeric( $data['storage_period_days'] )
				? (int) $data['storage_period_days']
				: null,
			isset( $data['fitting_rooms_count'] ) && is_numeric( $data['fitting_rooms_count'] )
				? (int) $data['fitting_rooms_count']
				: null,
			array_values( array_map( 'intval', $shipment_method_ids ) ),
			isset( $data['schedule'] ) && is_array( $data['schedule'] ) ? $data['schedule'] : array(),
			Restrictions::from_api(
				isset( $data['restrictions'] ) && is_array( $data['restrictions'] ) ? $data['restrictions'] : array()
			)
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы пунктов выдачи.
	 */
	public static function from_row( array $row ): self {
		$methods = isset( $row['shipment_method_ids'] ) ? (string) $row['shipment_method_ids'] : '';
		$methods = '' === $methods ? array() : array_map( 'intval', explode( ',', $methods ) );

		$schedule = isset( $row['schedule'] ) && is_string( $row['schedule'] ) && '' !== $row['schedule']
			? json_decode( $row['schedule'], true )
			: array();

		return new self(
			(int) ( $row['delivery_point_id'] ?? 0 ),
			isset( $row['name'] ) ? (string) $row['name'] : '',
			isset( $row['full_address'] ) ? (string) $row['full_address'] : '',
			isset( $row['city'] ) ? (string) $row['city'] : '',
			isset( $row['delivery_point_number'] ) ? (string) $row['delivery_point_number'] : '',
			isset( $row['type'] ) ? (string) $row['type'] : '',
			isset( $row['latitude'] ) && is_numeric( $row['latitude'] ) ? (float) $row['latitude'] : null,
			isset( $row['longitude'] ) && is_numeric( $row['longitude'] ) ? (float) $row['longitude'] : null,
			! empty( $row['is_active'] ),
			! empty( $row['is_bulky'] ),
			isset( $row['storage_period_days'] ) && is_numeric( $row['storage_period_days'] )
				? (int) $row['storage_period_days']
				: null,
			isset( $row['fitting_rooms_count'] ) && is_numeric( $row['fitting_rooms_count'] )
				? (int) $row['fitting_rooms_count']
				: null,
			$methods,
			is_array( $schedule ) ? $schedule : array(),
			Restrictions::from_row( $row )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$restrictions = $this->restrictions?->to_row() ?? array();

		return array_merge(
			$restrictions,
			array(
				'delivery_point_id'     => $this->delivery_point_id,
				'name'                  => $this->name,
				'full_address'          => $this->full_address,
				'city'                  => $this->city,
				'delivery_point_number' => $this->delivery_point_number,
				'type'                  => $this->type,
				'latitude'              => $this->latitude,
				'longitude'             => $this->longitude,
				'is_active'             => $this->is_active ? 1 : 0,
				'is_bulky'              => $this->is_bulky ? 1 : 0,
				'storage_period_days'   => $this->storage_period_days,
				'fitting_rooms_count'   => $this->fitting_rooms_count,
				'shipment_method_ids'   => implode( ',', $this->shipment_method_ids ),
				'schedule'              => array() === $this->schedule ? '' : (string) wp_json_encode( $this->schedule ),
			)
		);
	}

	/**
	 * Предварительный фильтр перед check-availability: закрытая точка не
	 * подходит никогда, остальное решают ограничения.
	 */
	public function accepts( Dimensions $parcel, Money $declared_value ): bool {
		if ( ! $this->is_active ) {
			return false;
		}

		return null === $this->restrictions || $this->restrictions->accepts( $parcel, $declared_value );
	}

	/**
	 * Пустой список методов — значит, из delivery-point/list он не приходил;
	 * ограничивать нечем, отфильтрует Ozon.
	 */
	public function supports_shipment_method( int $shipment_method_id ): bool {
		if ( array() === $this->shipment_method_ids ) {
			return true;
		}

		return in_array( $shipment_method_id, $this->shipment_method_ids, true );
	}

	/**
	 * Города отдельным полем Ozon не отдаёт, поэтому он вытаскивается из
	 * `full_address` эвристикой. Разбор адресов — та ещё лотерея, поэтому
	 * результат проходит через фильтр: чужая правка пишется сниппетом.
	 */
	private static function city_from_address( string $address ): string {
		$city  = '';
		$parts = array_map( 'trim', explode( ',', $address ) );

		foreach ( $parts as $part ) {
			// Индекс и пустые куски пропускаем.
			if ( '' === $part || 1 === preg_match( '/^\d+$/', $part ) ) {
				continue;
			}

			// Улицы, дома и прочее — это уже не город.
			if ( 1 === preg_match( '/^(ул|улица|д|дом|пр-т|проспект|пер|переулок|стр|корп|кв|ш|шоссе)\b\.?/ui', $part ) ) {
				break;
			}

			$city = (string) preg_replace( '/^(г|гор|город|пос|посёлок|поселок|с|село|д|деревня)\.?\s+/ui', '', $part );
			break;
		}

		/**
		 * Город пункта выдачи, вытащенный из адреса.
		 *
		 * @param string $city    Результат разбора.
		 * @param string $address Исходный адрес Ozon.
		 */
		return (string) apply_filters( 'ozon_delivery_point_city', trim( $city ), $address );
	}
}
