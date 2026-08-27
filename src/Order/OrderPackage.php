<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Перевод заказа WooCommerce в формат пакета доставки.
 *
 * Формат совпадает с тем, что WooCommerce передаёт в calculate_shipping,
 * поэтому PackageReader и Packer работают с заказом без единой правки —
 * и упаковка при передаче заказа получается ровно той же, что показывалась
 * покупателю при расчёте.
 */
final class OrderPackage {

	/**
	 * @return array<string, mixed>
	 */
	public static function from_order( object $order ): array {
		$contents = array();

		if ( ! is_callable( array( $order, 'get_items' ) ) ) {
			return array( 'contents' => $contents );
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product' ) ) ) {
				continue;
			}

			$product = $item->get_product();

			// Товар мог быть удалён из каталога после оформления заказа.
			if ( ! is_object( $product ) ) {
				continue;
			}

			$contents[] = array(
				'data'       => $product,
				'quantity'   => is_callable( array( $item, 'get_quantity' ) ) ? (int) $item->get_quantity() : 1,
				'line_total' => is_callable( array( $item, 'get_total' ) ) ? (float) $item->get_total() : 0.0,
				'line_tax'   => is_callable( array( $item, 'get_total_tax' ) ) ? (float) $item->get_total_tax() : 0.0,
			);
		}

		return array( 'contents' => $contents );
	}
}
