<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Spoki\OzonDelivery\Shipping\DeclaredValue;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class DeclaredValueTest extends TestCase {

	public function test_full_value_is_the_default(): void {
		$value = ( new DeclaredValue() )->for_subtotal( new Money( '2500.00', 'RUB' ) );

		self::assertSame( '2500.00', $value->amount );
		self::assertSame( 'RUB', $value->currency_code );
	}

	public function test_percentage_is_applied(): void {
		$value = ( new DeclaredValue( 50 ) )->for_subtotal( new Money( '2500.00', 'RUB' ) );

		self::assertSame( '1250.00', $value->amount );
	}

	/**
	 * Правило 9: проценты считаются в минорных единицах, без float.
	 */
	public function test_odd_percentage_does_not_drift(): void {
		$value = ( new DeclaredValue( 33 ) )->for_subtotal( new Money( '100.00', 'RUB' ) );

		self::assertSame( '33.00', $value->amount );
	}

	public function test_rounding_stays_in_minor_units(): void {
		$value = ( new DeclaredValue( 33 ) )->for_subtotal( new Money( '10.00', 'RUB' ) );

		self::assertSame( '3.30', $value->amount );
	}

	public function test_zero_subtotal_gives_zero_value(): void {
		$value = ( new DeclaredValue( 100 ) )->for_subtotal( Money::zero( 'RUB' ) );

		self::assertSame( 0, $value->minor_units() );
	}

	/**
	 * @dataProvider invalid_percent_provider
	 */
	public function test_percentage_is_clamped_to_a_sane_range( int $percent, int $expected_minor ): void {
		$value = ( new DeclaredValue( $percent ) )->for_subtotal( new Money( '100.00', 'RUB' ) );

		self::assertSame( $expected_minor, $value->minor_units() );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function invalid_percent_provider(): array {
		return array(
			'отрицательный' => array( -10, 0 ),
			'ноль'          => array( 0, 0 ),
			'больше 100'    => array( 500, 10000 ),
		);
	}

	public function test_currency_is_preserved(): void {
		$value = ( new DeclaredValue( 50 ) )->for_subtotal( new Money( '100.00', 'USD' ) );

		self::assertSame( 'USD', $value->currency_code );
	}
}
