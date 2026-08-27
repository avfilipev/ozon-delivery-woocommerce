<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;

/**
 * Ограничения пункта выдачи: вес, габариты и объявленная стоимость.
 *
 * Нужны, чтобы отсеять заведомо неподходящие точки **до** обращения к
 * delivery-point/check-availability, иначе API дёргается впустую. Финальное
 * слово всё равно за Ozon: здесь только предварительный фильтр.
 *
 * Все поля необязательные: чего Ozon не прислал — то не проверяется.
 *
 * @see docs/API.md, метод delivery-point/info
 */
final class Restrictions {

	public function __construct(
		private readonly ?int $min_weight_g = null,
		private readonly ?int $max_weight_g = null,
		private readonly ?int $max_length_mm = null,
		private readonly ?int $max_width_mm = null,
		private readonly ?int $max_height_mm = null,
		private readonly ?Money $min_price = null,
		private readonly ?Money $max_price = null
	) {
	}

	/**
	 * @param array<string, mixed> $restrictions Блок restrictions из ответа Ozon.
	 */
	public static function from_api( array $restrictions ): self {
		return new self(
			self::int_or_null( $restrictions['min_weight_g'] ?? null ),
			self::int_or_null( $restrictions['max_weight_g'] ?? null ),
			self::int_or_null( $restrictions['max_length_mm'] ?? null ),
			self::int_or_null( $restrictions['max_width_mm'] ?? null ),
			self::int_or_null( $restrictions['max_height_mm'] ?? null ),
			self::money_or_null( $restrictions['min_price'] ?? null ),
			self::money_or_null( $restrictions['max_price'] ?? null )
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы пунктов выдачи.
	 */
	public static function from_row( array $row ): self {
		$currency = isset( $row['price_currency'] ) ? (string) $row['price_currency'] : '';

		return new self(
			self::int_or_null( $row['min_weight_g'] ?? null ),
			self::int_or_null( $row['max_weight_g'] ?? null ),
			self::int_or_null( $row['max_length_mm'] ?? null ),
			self::int_or_null( $row['max_width_mm'] ?? null ),
			self::int_or_null( $row['max_height_mm'] ?? null ),
			self::money_from_minor( $row['min_price_minor'] ?? null, $currency ),
			self::money_from_minor( $row['max_price_minor'] ?? null, $currency )
		);
	}

	/**
	 * Деньги хранятся в минорных единицах целым числом — правило 9.
	 *
	 * @return array<string, int|string|null>
	 */
	public function to_row(): array {
		$currency = $this->min_price?->currency_code ?? $this->max_price?->currency_code ?? '';

		return array(
			'min_weight_g'    => $this->min_weight_g,
			'max_weight_g'    => $this->max_weight_g,
			'max_length_mm'   => $this->max_length_mm,
			'max_width_mm'    => $this->max_width_mm,
			'max_height_mm'   => $this->max_height_mm,
			'min_price_minor' => $this->min_price?->minor_units(),
			'max_price_minor' => $this->max_price?->minor_units(),
			'price_currency'  => $currency,
		);
	}

	public function accepts( Dimensions $parcel, Money $declared_value ): bool {
		return null === $this->rejection_reason( $parcel, $declared_value );
	}

	/**
	 * @return string|null Причина отказа для админки и лога, null — точка подходит.
	 */
	public function rejection_reason( Dimensions $parcel, Money $declared_value ): ?string {
		if ( null !== $this->min_weight_g && $parcel->weight_g < $this->min_weight_g ) {
			return sprintf( 'Вес меньше минимального для пункта выдачи: %d г.', $this->min_weight_g );
		}

		if ( null !== $this->max_weight_g && $parcel->weight_g > $this->max_weight_g ) {
			return sprintf( 'Вес больше максимального для пункта выдачи: %d г.', $this->max_weight_g );
		}

		$sides = array(
			'длина'  => array( $parcel->length_mm, $this->max_length_mm ),
			'ширина' => array( $parcel->width_mm, $this->max_width_mm ),
			'высота' => array( $parcel->height_mm, $this->max_height_mm ),
		);

		foreach ( $sides as $side => $pair ) {
			[ $actual, $limit ] = $pair;

			if ( null !== $limit && $actual > $limit ) {
				return sprintf( 'Габарит не подходит пункту выдачи: %s больше %d мм.', $side, $limit );
			}
		}

		return $this->price_rejection_reason( $declared_value );
	}

	/**
	 * Сравнивать суммы в разных валютах нельзя. Молча отбрасывать точку тоже
	 * неправильно — пусть решает check-availability на стороне Ozon.
	 */
	private function price_rejection_reason( Money $declared_value ): ?string {
		if ( null !== $this->min_price
			&& $this->min_price->currency_code === $declared_value->currency_code
			&& $declared_value->is_less_than( $this->min_price )
		) {
			return sprintf(
				'Объявленная стоимость меньше минимальной для пункта выдачи: %s.',
				$this->min_price->amount
			);
		}

		if ( null !== $this->max_price
			&& $this->max_price->currency_code === $declared_value->currency_code
			&& $declared_value->is_greater_than( $this->max_price )
		) {
			return sprintf(
				'Объявленная стоимость больше максимальной для пункта выдачи: %s.',
				$this->max_price->amount
			);
		}

		return null;
	}

	private static function int_or_null( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	private static function money_or_null( mixed $value ): ?Money {
		return is_array( $value ) && isset( $value['amount'] ) ? Money::from_array( $value ) : null;
	}

	private static function money_from_minor( mixed $minor, string $currency ): ?Money {
		return is_numeric( $minor ) ? Money::from_minor_units( (int) $minor, $currency ) : null;
	}
}
