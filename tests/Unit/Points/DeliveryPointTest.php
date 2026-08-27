<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Points;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class DeliveryPointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	/**
	 * Форма ответа delivery-point/info из docs/API.md.
	 *
	 * @return array<string, mixed>
	 */
	private static function api_point(): array {
		return array(
			'delivery_point_id'     => 42,
			'name'                  => 'Ozon Пункт выдачи',
			'delivery_point_number' => 'RU-MOW-0042',
			'type'                  => 'PICKUP_POINT',
			'full_address'          => 'г. Москва, ул. Тверская, д. 1',
			'coordinates'           => array(
				'latitude'  => 55.757,
				'longitude' => 37.615,
			),
			'schedule'              => array(
				array(
					'date'    => '2026-08-27',
					'periods' => array(
						array(
							'from_local' => '09:00',
							'to_local'   => '21:00',
						),
					),
				),
			),
			'is_active'             => true,
			'storage_period_days'   => 5,
			'fitting_rooms_count'   => 2,
			'is_bulky'              => false,
			'restrictions'          => array(
				'max_weight_g'  => 15000,
				'max_length_mm' => 600,
			),
		);
	}

	public function test_point_is_built_from_the_api_response(): void {
		$point = DeliveryPoint::from_api( self::api_point(), array( 100, 200 ) );

		self::assertSame( 42, $point->delivery_point_id );
		self::assertSame( 'Ozon Пункт выдачи', $point->name );
		self::assertSame( 'RU-MOW-0042', $point->delivery_point_number );
		self::assertSame( 'PICKUP_POINT', $point->type );
		self::assertSame( 'г. Москва, ул. Тверская, д. 1', $point->full_address );
		self::assertSame( 55.757, $point->latitude );
		self::assertSame( 37.615, $point->longitude );
		self::assertTrue( $point->is_active );
		self::assertFalse( $point->is_bulky );
		self::assertSame( 5, $point->storage_period_days );
		self::assertSame( 2, $point->fitting_rooms_count );
		self::assertSame( array( 100, 200 ), $point->shipment_method_ids );
	}

	public function test_missing_optional_fields_do_not_break_parsing(): void {
		$point = DeliveryPoint::from_api(
			array(
				'delivery_point_id' => 7,
				'name'              => 'Минимальный',
				'full_address'      => 'Адрес',
			)
		);

		self::assertSame( 7, $point->delivery_point_id );
		self::assertNull( $point->latitude );
		self::assertNull( $point->storage_period_days );
		self::assertSame( array(), $point->shipment_method_ids );
		self::assertSame( '', $point->delivery_point_number );
	}

	/**
	 * is_active в спеке обязателен, но если поля нет — считать точку рабочей
	 * нельзя: лучше скрыть, чем отправить заказ в закрытый ПВЗ.
	 */
	public function test_point_without_is_active_is_treated_as_inactive(): void {
		$point = DeliveryPoint::from_api(
			array(
				'delivery_point_id' => 7,
				'name'              => 'Без признака',
				'full_address'      => 'Адрес',
			)
		);

		self::assertFalse( $point->is_active );
	}

	public function test_restrictions_are_applied_to_the_parcel(): void {
		$point = DeliveryPoint::from_api( self::api_point() );

		self::assertTrue(
			$point->accepts( new Dimensions( 1000, 300, 200, 100 ), new Money( '1000.00', 'RUB' ) )
		);
		self::assertFalse(
			$point->accepts( new Dimensions( 99000, 300, 200, 100 ), new Money( '1000.00', 'RUB' ) )
		);
	}

	public function test_inactive_point_accepts_nothing(): void {
		$data              = self::api_point();
		$data['is_active'] = false;

		$point = DeliveryPoint::from_api( $data );

		self::assertFalse(
			$point->accepts( new Dimensions( 1000, 300, 200, 100 ), new Money( '1000.00', 'RUB' ) )
		);
	}

	public function test_point_serialises_to_a_storage_row(): void {
		$row = DeliveryPoint::from_api( self::api_point(), array( 100, 200 ) )->to_row();

		self::assertSame( 42, $row['delivery_point_id'] );
		self::assertSame( 'Ozon Пункт выдачи', $row['name'] );
		self::assertSame( 1, $row['is_active'] );
		self::assertSame( 0, $row['is_bulky'] );
		self::assertSame( 15000, $row['max_weight_g'] );
		self::assertSame( '100,200', $row['shipment_method_ids'] );
	}

	public function test_row_round_trip_preserves_the_point(): void {
		$original = DeliveryPoint::from_api( self::api_point(), array( 100, 200 ) );

		$restored = DeliveryPoint::from_row( $original->to_row() );

		self::assertSame( $original->delivery_point_id, $restored->delivery_point_id );
		self::assertSame( $original->name, $restored->name );
		self::assertSame( $original->full_address, $restored->full_address );
		self::assertSame( $original->latitude, $restored->latitude );
		self::assertSame( $original->is_active, $restored->is_active );
		self::assertSame( $original->shipment_method_ids, $restored->shipment_method_ids );
		self::assertEquals( $original->schedule, $restored->schedule );
	}

	public function test_city_is_derived_from_the_address(): void {
		$point = DeliveryPoint::from_api( self::api_point() );

		self::assertSame( 'Москва', $point->city );
	}

	/**
	 * @dataProvider address_provider
	 */
	public function test_city_is_derived_from_various_address_shapes( string $address, string $expected ): void {
		$point = DeliveryPoint::from_api(
			array(
				'delivery_point_id' => 1,
				'name'              => 'Точка',
				'full_address'      => $address,
			)
		);

		self::assertSame( $expected, $point->city );
	}

	/**
	 * @return array<string, array{0: string,1: string}>
	 */
	public static function address_provider(): array {
		return array(
			'с индексом'       => array( '101000, г. Москва, ул. Мясницкая, д. 1', 'Москва' ),
			'без сокращения'   => array( 'Москва, ул. Тверская, д. 1', 'Москва' ),
			'город с пробелом' => array( 'г. Нижний Новгород, ул. Большая, д. 5', 'Нижний Новгород' ),
			'посёлок'          => array( 'пос. Развилка, д. 3', 'Развилка' ),
			'пустой адрес'     => array( '', '' ),
		);
	}

	public function test_supports_shipment_method(): void {
		$point = DeliveryPoint::from_api( self::api_point(), array( 100, 200 ) );

		self::assertTrue( $point->supports_shipment_method( 100 ) );
		self::assertFalse( $point->supports_shipment_method( 999 ) );
	}

	/**
	 * Если список методов пуст, ограничивать нечем — фильтрацию делает Ozon.
	 */
	public function test_point_without_known_methods_supports_any(): void {
		$point = DeliveryPoint::from_api( self::api_point() );

		self::assertTrue( $point->supports_shipment_method( 999 ) );
	}
}
