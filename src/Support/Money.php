<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Support;

use InvalidArgumentException;

/**
 * Денежная сумма Ozon: `{ amount: string, currency_code: string }`.
 *
 * Правило 9: amount — строка, никакой float-арифметики. Всё сравнение и
 * сложение идёт в минорных единицах (копейках) целыми числами, поэтому
 * 0.10 + 0.20 здесь ровно 0.30, а не 0.30000000000000004.
 *
 * Число знаков дробной части в спецификации не оговорено; принято два, как
 * для рубля. Если у Ozon встретится валюта с другой дробной частью — это
 * место придётся расширить.
 *
 * @see docs/API.md, раздел «Единицы и типы, на которых легко ошибиться»
 */
final class Money {

	private const FRACTION_DIGITS = 2;

	private const MINOR_PER_MAJOR = 100;

	private readonly int $minor_units;

	public function __construct(
		public readonly string $amount,
		public readonly string $currency_code
	) {
		$this->minor_units = self::parse( $amount );
	}

	/**
	 * @param array<string, mixed> $money Объект денег из ответа Ozon.
	 */
	public static function from_array( array $money ): self {
		if ( ! isset( $money['amount'] ) || ! is_scalar( $money['amount'] ) ) {
			throw new InvalidArgumentException( 'В объекте денег Ozon нет поля amount.' );
		}

		$currency = isset( $money['currency_code'] ) && is_string( $money['currency_code'] )
			? $money['currency_code']
			: '';

		return new self( (string) $money['amount'], $currency );
	}

	public static function from_minor_units( int $minor_units, string $currency_code ): self {
		$sign  = $minor_units < 0 ? '-' : '';
		$minor = abs( $minor_units );

		return new self(
			sprintf(
				'%s%d.%0' . self::FRACTION_DIGITS . 'd',
				$sign,
				intdiv( $minor, self::MINOR_PER_MAJOR ),
				$minor % self::MINOR_PER_MAJOR
			),
			$currency_code
		);
	}

	public static function zero( string $currency_code ): self {
		return self::from_minor_units( 0, $currency_code );
	}

	/**
	 * @return array{amount: string, currency_code: string}
	 */
	public function to_array(): array {
		return array(
			'amount'        => $this->amount,
			'currency_code' => $this->currency_code,
		);
	}

	public function minor_units(): int {
		return $this->minor_units;
	}

	public function add( self $other ): self {
		$this->assert_same_currency( $other );

		return self::from_minor_units( $this->minor_units + $other->minor_units, $this->currency_code );
	}

	public function is_less_than( self $other ): bool {
		$this->assert_same_currency( $other );

		return $this->minor_units < $other->minor_units;
	}

	public function is_greater_than( self $other ): bool {
		$this->assert_same_currency( $other );

		return $this->minor_units > $other->minor_units;
	}

	public function equals( self $other ): bool {
		$this->assert_same_currency( $other );

		return $this->minor_units === $other->minor_units;
	}

	private function assert_same_currency( self $other ): void {
		if ( $this->currency_code !== $other->currency_code ) {
			throw new InvalidArgumentException(
				sprintf(
					'Нельзя сравнивать и складывать разные валюты: %s и %s.',
					$this->currency_code,
					$other->currency_code
				)
			);
		}
	}

	/**
	 * `1500.45` → 150045. Разделителем принимается и точка, и запятая.
	 */
	private static function parse( string $amount ): int {
		$normalised = str_replace( ',', '.', trim( $amount ) );

		if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $normalised ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Сумму «%s» не разобрать как денежное значение.', $amount )
			);
		}

		$negative = str_starts_with( $normalised, '-' );
		$digits   = ltrim( $normalised, '-' );

		[ $major, $minor ] = array_pad( explode( '.', $digits, 2 ), 2, '' );

		$minor = str_pad( substr( $minor, 0, self::FRACTION_DIGITS ), self::FRACTION_DIGITS, '0' );

		$total = (int) $major * self::MINOR_PER_MAJOR + (int) $minor;

		return $negative ? -$total : $total;
	}
}
