<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use InvalidArgumentException;

/**
 * Габариты отправления в единицах Ozon: граммы и миллиметры.
 *
 * Правило 8: конвертация из единиц WooCommerce живёт только здесь. Нигде
 * больше в плагине не должно быть ни коэффициентов, ни делений на 1000.
 *
 * @see docs/API.md, раздел «Единицы и типы, на которых легко ошибиться»
 */
final class Dimensions {

	/**
	 * Сколько граммов в одной единице веса WooCommerce.
	 *
	 * @var array<string, float>
	 */
	private const GRAMS_PER_WEIGHT_UNIT = array(
		'kg'  => 1000.0,
		'g'   => 1.0,
		'lbs' => 453.59237,
		'oz'  => 28.349523125,
	);

	/**
	 * Сколько миллиметров в одной единице длины WooCommerce.
	 *
	 * @var array<string, float>
	 */
	private const MM_PER_DIMENSION_UNIT = array(
		'm'  => 1000.0,
		'cm' => 10.0,
		'mm' => 1.0,
		'in' => 25.4,
		'yd' => 914.4,
	);

	public function __construct(
		public readonly int $weight_g,
		public readonly int $length_mm,
		public readonly int $width_mm,
		public readonly int $height_mm
	) {
		foreach ( $this->to_array() as $field => $value ) {
			if ( $value < 0 ) {
				throw new InvalidArgumentException(
					sprintf( 'Габарит %s не может быть отрицательным: %d.', $field, $value )
				);
			}
		}
	}

	/**
	 * Переводит значения из единиц, настроенных в WooCommerce.
	 *
	 * @param string $weight_unit    kg, g, lbs или oz.
	 * @param string $dimension_unit m, cm, mm, in или yd.
	 */
	public static function from_units(
		float $weight,
		string $weight_unit,
		float $length,
		float $width,
		float $height,
		string $dimension_unit
	): self {
		$grams = self::factor( self::GRAMS_PER_WEIGHT_UNIT, $weight_unit, 'веса' );
		$mm    = self::factor( self::MM_PER_DIMENSION_UNIT, $dimension_unit, 'длины' );

		return new self(
			self::to_int( $weight * $grams ),
			self::to_int( $length * $mm ),
			self::to_int( $width * $mm ),
			self::to_int( $height * $mm )
		);
	}

	/**
	 * @return array{weight_g: int, length_mm: int, width_mm: int, height_mm: int}
	 */
	public function to_array(): array {
		return array(
			'weight_g'  => $this->weight_g,
			'length_mm' => $this->length_mm,
			'width_mm'  => $this->width_mm,
			'height_mm' => $this->height_mm,
		);
	}

	public function is_empty(): bool {
		return 0 === $this->weight_g
			&& 0 === $this->length_mm
			&& 0 === $this->width_mm
			&& 0 === $this->height_mm;
	}

	/**
	 * Объединяет габариты двух вложений в одно отправление: вес суммируется,
	 * по каждой стороне берётся максимум. Это грубая оценка «одна коробка на
	 * заказ» — точная упаковка появится вместе с расчётом тарифа.
	 */
	public function merge( self $other ): self {
		return new self(
			$this->weight_g + $other->weight_g,
			max( $this->length_mm, $other->length_mm ),
			max( $this->width_mm, $other->width_mm ),
			max( $this->height_mm, $other->height_mm )
		);
	}

	public function longest_side_mm(): int {
		return max( $this->length_mm, $this->width_mm, $this->height_mm );
	}

	/**
	 * @param array<string, float> $factors
	 */
	private static function factor( array $factors, string $unit, string $kind ): float {
		$unit = strtolower( trim( $unit ) );

		if ( ! isset( $factors[ $unit ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Единица %s «%s» не поддерживается. Допустимы: %s.',
					$kind,
					$unit,
					implode( ', ', array_keys( $factors ) )
				)
			);
		}

		return $factors[ $unit ];
	}

	/**
	 * Ozon принимает целые. Ненулевое значение не должно схлопываться в ноль:
	 * иначе товар весом 0,4 грамма поедет как невесомый.
	 */
	private static function to_int( float $value ): int {
		if ( $value <= 0.0 ) {
			return 0;
		}

		return max( 1, (int) round( $value ) );
	}
}
