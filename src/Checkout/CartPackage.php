<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

/**
 * Текущий пакет доставки WooCommerce.
 *
 * В версии 1 отправление на заказ одно, поэтому и пакет берётся первый.
 * Корзины может не быть вовсе — в админке, в WP-CLI, в REST-запросе, — и это
 * не повод ронять вызывающий код.
 */
final class CartPackage {

	/**
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		/** @var mixed $woocommerce */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) ) {
			return null;
		}

		$cart = $woocommerce->cart ?? null;

		if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_shipping_packages' ) ) ) {
			return null;
		}

		$packages = $cart->get_shipping_packages();

		if ( ! is_array( $packages ) || array() === $packages ) {
			return null;
		}

		$first = reset( $packages );

		return is_array( $first ) ? $first : null;
	}
}
