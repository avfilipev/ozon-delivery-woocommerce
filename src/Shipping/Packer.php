<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

/**
 * Упаковка корзины в одно отправление.
 *
 * В версии 1 отправление одно на заказ: вес суммируется, по каждой стороне
 * берётся максимум, сверху добавляется запас на упаковку. Это грубая оценка,
 * но честная: настоящая раскладка по коробкам появится вместе с несколькими
 * отправлениями на заказ.
 *
 * @see docs/PLAN.md, раздел «Габариты обязательны в каждом запросе»
 */
final class Packer {

	/**
	 * Минимум, ниже которого отправление не имеет смысла: нулевой вес Ozon
	 * не примет.
	 */
	private const MIN_WEIGHT_G = 1;

	private const MIN_SIDE_MM = 1;

	public function __construct(
		private readonly Dimensions $fallback,
		private readonly int $padding_mm = 0
	) {
	}

	/**
	 * @param CartItem[] $items
	 */
	public function pack( array $items ): Dimensions {
		$packed = null;

		foreach ( $items as $item ) {
			if ( ! $item->is_shippable() ) {
				continue;
			}

			$line = $item->with_fallback( $this->fallback )->line_dimensions();

			$packed = null === $packed ? $line : $packed->merge( $line );
		}

		$packed = $this->with_padding( $packed ?? $this->fallback );

		/**
		 * Итоговые габариты отправления перед отправкой в Ozon.
		 *
		 * @param Dimensions $packed Рассчитанные габариты.
		 * @param CartItem[] $items  Состав корзины.
		 */
		/** @var mixed $filtered Фильтр может вернуть что угодно: сниппет пишет третья сторона. */
		$filtered = apply_filters( 'ozon_delivery_parcel_dimensions', $packed, $items );

		return $this->at_least_minimal( $filtered instanceof Dimensions ? $filtered : $packed );
	}

	/**
	 * Запас идёт только на стороны: обёртка не прибавляет веса настолько,
	 * чтобы это стоило считать.
	 */
	private function with_padding( Dimensions $dimensions ): Dimensions {
		if ( 0 === $this->padding_mm ) {
			return $dimensions;
		}

		return new Dimensions(
			$dimensions->weight_g,
			$dimensions->length_mm + $this->padding_mm,
			$dimensions->width_mm + $this->padding_mm,
			$dimensions->height_mm + $this->padding_mm
		);
	}

	private function at_least_minimal( Dimensions $dimensions ): Dimensions {
		return new Dimensions(
			max( self::MIN_WEIGHT_G, $dimensions->weight_g ),
			max( self::MIN_SIDE_MM, $dimensions->length_mm ),
			max( self::MIN_SIDE_MM, $dimensions->width_mm ),
			max( self::MIN_SIDE_MM, $dimensions->height_mm )
		);
	}
}
