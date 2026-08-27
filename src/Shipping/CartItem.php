<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

/**
 * Позиция корзины в том виде, в каком её видит упаковщик: габариты одной
 * штуки и количество.
 */
final class CartItem {

	public function __construct(
		public readonly Dimensions $dimensions,
		public readonly int $quantity
	) {
	}

	public function is_shippable(): bool {
		return $this->quantity > 0;
	}

	/**
	 * Габариты всей позиции: вес умножается на количество, стороны — нет.
	 * Три одинаковые коробки весят втрое больше, но не становятся втрое длиннее.
	 */
	public function line_dimensions(): Dimensions {
		return new Dimensions(
			$this->dimensions->weight_g * max( 0, $this->quantity ),
			$this->dimensions->length_mm,
			$this->dimensions->width_mm,
			$this->dimensions->height_mm
		);
	}

	/**
	 * Товар без заполненных габаритов — обычное дело, а Ozon требует их всегда.
	 */
	public function with_fallback( Dimensions $fallback ): self {
		return $this->dimensions->is_empty()
			? new self( $fallback, $this->quantity )
			: $this;
	}
}
