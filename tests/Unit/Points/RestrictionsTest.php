<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Points;

use Spoki\OzonDelivery\Points\Restrictions;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class RestrictionsTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function api_restrictions(): array {
		return array(
			'min_weight_g'  => 100,
			'max_weight_g'  => 15000,
			'max_length_mm' => 600,
			'max_width_mm'  => 400,
			'max_height_mm' => 300,
			'min_price'     => array(
				'amount'        => '1.00',
				'currency_code' => 'RUB',
			),
			'max_price'     => array(
				'amount'        => '150000.00',
				'currency_code' => 'RUB',
			),
		);
	}

	private static function fitting_parcel(): Dimensions {
		return new Dimensions( 1000, 300, 200, 100 );
	}

	private static function declared_value(): Money {
		return new Money( '2500.00', 'RUB' );
	}

	public function test_parcel_within_all_limits_is_accepted(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertTrue( $restrictions->accepts( self::fitting_parcel(), self::declared_value() ) );
		self::assertNull( $restrictions->rejection_reason( self::fitting_parcel(), self::declared_value() ) );
	}

	public function test_too_heavy_parcel_is_rejected(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		$reason = $restrictions->rejection_reason( new Dimensions( 20000, 300, 200, 100 ), self::declared_value() );

		self::assertIsString( $reason );
		self::assertStringContainsString( 'вес', mb_strtolower( $reason ) );
	}

	public function test_too_light_parcel_is_rejected(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertFalse( $restrictions->accepts( new Dimensions( 50, 300, 200, 100 ), self::declared_value() ) );
	}

	/**
	 * @dataProvider oversized_provider
	 */
	public function test_oversized_parcel_is_rejected( Dimensions $parcel ): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertFalse( $restrictions->accepts( $parcel, self::declared_value() ) );
	}

	/**
	 * @return array<string, array{0: Dimensions}>
	 */
	public static function oversized_provider(): array {
		return array(
			'длина'  => array( new Dimensions( 1000, 700, 200, 100 ) ),
			'ширина' => array( new Dimensions( 1000, 300, 500, 100 ) ),
			'высота' => array( new Dimensions( 1000, 300, 200, 400 ) ),
		);
	}

	public function test_declared_value_above_the_limit_is_rejected(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		$reason = $restrictions->rejection_reason(
			self::fitting_parcel(),
			new Money( '200000.00', 'RUB' )
		);

		self::assertIsString( $reason );
		self::assertStringContainsString( 'стоимост', mb_strtolower( $reason ) );
	}

	public function test_declared_value_below_the_limit_is_rejected(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertFalse(
			$restrictions->accepts( self::fitting_parcel(), new Money( '0.50', 'RUB' ) )
		);
	}

	public function test_value_exactly_on_the_boundary_is_accepted(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertTrue(
			$restrictions->accepts( new Dimensions( 15000, 600, 400, 300 ), new Money( '150000.00', 'RUB' ) )
		);
	}

	/**
	 * Ограничения — необязательный блок: если Ozon их не прислал,
	 * отсеивать точку заранее не за что.
	 */
	public function test_absent_restrictions_accept_everything(): void {
		$restrictions = Restrictions::from_api( array() );

		self::assertTrue( $restrictions->accepts( new Dimensions( 999999, 9999, 9999, 9999 ), self::declared_value() ) );
	}

	public function test_partial_restrictions_check_only_what_is_known(): void {
		$restrictions = Restrictions::from_api( array( 'max_weight_g' => 5000 ) );

		self::assertTrue( $restrictions->accepts( new Dimensions( 4000, 9999, 9999, 9999 ), self::declared_value() ) );
		self::assertFalse( $restrictions->accepts( new Dimensions( 6000, 10, 10, 10 ), self::declared_value() ) );
	}

	/**
	 * Валюта заказа может не совпадать с валютой ограничений: сравнивать их
	 * нельзя, но и молча отбрасывать точку тоже — пусть решает Ozon.
	 */
	public function test_price_in_another_currency_is_not_compared(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		self::assertTrue(
			$restrictions->accepts( self::fitting_parcel(), new Money( '999999.00', 'USD' ) )
		);
	}

	public function test_round_trip_through_storage_keeps_the_limits(): void {
		$restrictions = Restrictions::from_api( self::api_restrictions() );

		$restored = Restrictions::from_row( $restrictions->to_row() );

		self::assertFalse( $restored->accepts( new Dimensions( 20000, 300, 200, 100 ), self::declared_value() ) );
		self::assertTrue( $restored->accepts( self::fitting_parcel(), self::declared_value() ) );
	}
}
