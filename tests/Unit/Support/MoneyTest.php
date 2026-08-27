<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Support;

use InvalidArgumentException;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class MoneyTest extends TestCase {

	public function test_amount_and_currency_are_kept_as_given(): void {
		$money = new Money( '1500.00', 'RUB' );

		self::assertSame( '1500.00', $money->amount );
		self::assertSame( 'RUB', $money->currency_code );
	}

	/**
	 * @dataProvider minor_units_provider
	 */
	public function test_amount_is_parsed_into_minor_units( string $amount, int $expected ): void {
		self::assertSame( $expected, ( new Money( $amount, 'RUB' ) )->minor_units() );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function minor_units_provider(): array {
		return array(
			'целое'              => array( '1500', 150000 ),
			'две цифры дробной'  => array( '1500.00', 150000 ),
			'копейки'            => array( '1500.45', 150045 ),
			'одна цифра дробной' => array( '1500.5', 150050 ),
			'ноль'               => array( '0', 0 ),
			'ноль с дробной'     => array( '0.00', 0 ),
			'меньше рубля'       => array( '0.99', 99 ),
			'запятая как в RU'   => array( '1500,45', 150045 ),
			'пробелы по краям'   => array( ' 1500.45 ', 150045 ),
		);
	}

	public function test_from_array_reads_the_ozon_money_object(): void {
		$money = Money::from_array(
			array(
				'amount'        => '2500.00',
				'currency_code' => 'RUB',
			)
		);

		self::assertSame( '2500.00', $money->amount );
		self::assertSame( 'RUB', $money->currency_code );
	}

	public function test_to_array_returns_the_ozon_money_object(): void {
		self::assertSame(
			array(
				'amount'        => '2500.00',
				'currency_code' => 'RUB',
			),
			( new Money( '2500.00', 'RUB' ) )->to_array()
		);
	}

	/**
	 * Правило 9: amount — строка, никакой float-арифметики.
	 */
	public function test_to_array_amount_is_always_a_string(): void {
		$array = Money::from_minor_units( 150045, 'RUB' )->to_array();

		self::assertIsString( $array['amount'] );
		self::assertSame( '1500.45', $array['amount'] );
	}

	public function test_from_minor_units_pads_the_fraction(): void {
		self::assertSame( '0.05', Money::from_minor_units( 5, 'RUB' )->amount );
		self::assertSame( '0.50', Money::from_minor_units( 50, 'RUB' )->amount );
		self::assertSame( '1.00', Money::from_minor_units( 100, 'RUB' )->amount );
	}

	public function test_comparison_is_exact_for_amounts_that_break_floats(): void {
		// 0.1 + 0.2 в float даёт 0.30000000000000004.
		$sum = ( new Money( '0.10', 'RUB' ) )->add( new Money( '0.20', 'RUB' ) );

		self::assertSame( '0.30', $sum->amount );
		self::assertTrue( $sum->equals( new Money( '0.3', 'RUB' ) ) );
	}

	public function test_add_sums_minor_units(): void {
		$sum = ( new Money( '1500.45', 'RUB' ) )->add( new Money( '99.55', 'RUB' ) );

		self::assertSame( '1600.00', $sum->amount );
	}

	public function test_is_less_than(): void {
		self::assertTrue( ( new Money( '100.00', 'RUB' ) )->is_less_than( new Money( '100.01', 'RUB' ) ) );
		self::assertFalse( ( new Money( '100.00', 'RUB' ) )->is_less_than( new Money( '100.00', 'RUB' ) ) );
	}

	public function test_is_greater_than(): void {
		self::assertTrue( ( new Money( '100.01', 'RUB' ) )->is_greater_than( new Money( '100.00', 'RUB' ) ) );
		self::assertFalse( ( new Money( '100.00', 'RUB' ) )->is_greater_than( new Money( '100.00', 'RUB' ) ) );
	}

	public function test_equals_compares_value_not_formatting(): void {
		self::assertTrue( ( new Money( '100', 'RUB' ) )->equals( new Money( '100.00', 'RUB' ) ) );
	}

	public function test_comparing_different_currencies_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Money( '100.00', 'RUB' ) )->is_less_than( new Money( '100.00', 'USD' ) );
	}

	public function test_adding_different_currencies_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Money( '100.00', 'RUB' ) )->add( new Money( '100.00', 'USD' ) );
	}

	public function test_from_array_without_amount_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Money::from_array( array( 'currency_code' => 'RUB' ) );
	}

	public function test_zero_helper(): void {
		$zero = Money::zero( 'RUB' );

		self::assertSame( 0, $zero->minor_units() );
		self::assertSame( '0.00', $zero->amount );
	}
}
