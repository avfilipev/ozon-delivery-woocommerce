<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use InvalidArgumentException;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Tests\TestCase;

final class DimensionsTest extends TestCase {

	public function test_to_array_uses_the_ozon_field_names(): void {
		$dimensions = new Dimensions( 1200, 300, 200, 100 );

		self::assertSame(
			array(
				'weight_g'  => 1200,
				'length_mm' => 300,
				'width_mm'  => 200,
				'height_mm' => 100,
			),
			$dimensions->to_array()
		);
	}

	/**
	 * @dataProvider weight_unit_provider
	 */
	public function test_weight_is_converted_to_grams( float $value, string $unit, int $expected ): void {
		$dimensions = Dimensions::from_units( $value, $unit, 1, 1, 1, 'mm' );

		self::assertSame( $expected, $dimensions->weight_g );
	}

	/**
	 * @return array<string, array{0: float, 1: string, 2: int}>
	 */
	public static function weight_unit_provider(): array {
		return array(
			'килограммы' => array( 1.5, 'kg', 1500 ),
			'граммы'     => array( 750.0, 'g', 750 ),
			'фунты'      => array( 1.0, 'lbs', 454 ),
			'унции'      => array( 1.0, 'oz', 28 ),
			'округление' => array( 0.0004, 'kg', 1 ),
		);
	}

	/**
	 * @dataProvider dimension_unit_provider
	 */
	public function test_dimensions_are_converted_to_millimetres( float $value, string $unit, int $expected ): void {
		$dimensions = Dimensions::from_units( 1, 'g', $value, $value, $value, $unit );

		self::assertSame( $expected, $dimensions->length_mm );
		self::assertSame( $expected, $dimensions->width_mm );
		self::assertSame( $expected, $dimensions->height_mm );
	}

	/**
	 * @return array<string, array{0: float, 1: string, 2: int}>
	 */
	public static function dimension_unit_provider(): array {
		return array(
			'метры'      => array( 1.0, 'm', 1000 ),
			'сантиметры' => array( 25.0, 'cm', 250 ),
			'миллиметры' => array( 300.0, 'mm', 300 ),
			'дюймы'      => array( 1.0, 'in', 25 ),
			'ярды'       => array( 1.0, 'yd', 914 ),
		);
	}

	/**
	 * Пустой вес и габариты у товара — обычное дело; в Ozon уходят нули,
	 * а подстановка значений по умолчанию решается уровнем выше.
	 */
	public function test_empty_values_become_zero(): void {
		$dimensions = Dimensions::from_units( 0, 'kg', 0, 0, 0, 'cm' );

		self::assertSame( 0, $dimensions->weight_g );
		self::assertSame( 0, $dimensions->length_mm );
	}

	public function test_negative_values_are_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new Dimensions( -1, 10, 10, 10 );
	}

	public function test_unknown_weight_unit_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Dimensions::from_units( 1, 'stone', 1, 1, 1, 'mm' );
	}

	public function test_unknown_dimension_unit_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Dimensions::from_units( 1, 'g', 1, 1, 1, 'furlong' );
	}

	public function test_is_empty_when_nothing_is_set(): void {
		self::assertTrue( ( new Dimensions( 0, 0, 0, 0 ) )->is_empty() );
		self::assertFalse( ( new Dimensions( 1, 0, 0, 0 ) )->is_empty() );
	}

	/**
	 * Габариты складываются не как вес: коробка не растёт по всем сторонам,
	 * берётся максимум по каждой стороне, а вес суммируется.
	 */
	public function test_merge_sums_weight_and_takes_the_largest_side(): void {
		$merged = ( new Dimensions( 500, 300, 100, 50 ) )
			->merge( new Dimensions( 700, 200, 250, 40 ) );

		self::assertSame( 1200, $merged->weight_g );
		self::assertSame( 300, $merged->length_mm );
		self::assertSame( 250, $merged->width_mm );
		self::assertSame( 50, $merged->height_mm );
	}

	public function test_longest_side_is_reported(): void {
		self::assertSame( 300, ( new Dimensions( 0, 300, 200, 100 ) )->longest_side_mm() );
	}
}
