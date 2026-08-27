<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Support\Money;

/**
 * Чтение корзины WooCommerce: позиции и сумма.
 *
 * Единицы берутся из настроек WooCommerce и передаются снаружи, а перевод в
 * граммы и миллиметры делает Dimensions — правило 8.
 */
final class PackageReader {

	public function __construct(
		private readonly string $weight_unit,
		private readonly string $dimension_unit
	) {
	}

	/**
	 * @param array<string, mixed> $package Пакет доставки WooCommerce.
	 *
	 * @return CartItem[]
	 */
	public function items( array $package ): array {
		$items = array();

		foreach ( $this->contents( $package ) as $entry ) {
			$product = $entry['data'] ?? null;

			// is_callable, а не method_exists: часть объектов WooCommerce отвечает
			// на вызовы через __call, и method_exists их не видит.
			if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_weight' ) ) ) {
				continue;
			}

			// Виртуальные товары физически не едут.
			if ( is_callable( array( $product, 'needs_shipping' ) ) && ! $product->needs_shipping() ) {
				continue;
			}

			$items[] = new CartItem(
				Dimensions::from_units(
					$this->measure( $product, 'get_weight' ),
					$this->weight_unit,
					$this->measure( $product, 'get_length' ),
					$this->measure( $product, 'get_width' ),
					$this->measure( $product, 'get_height' ),
					$this->dimension_unit
				),
				(int) ( $entry['quantity'] ?? 0 )
			);
		}

		return $items;
	}

	/**
	 * Сумма позиций вместе с налогом: объявленная стоимость должна отражать
	 * то, что покупатель реально платит за товар.
	 *
	 * @param array<string, mixed> $package
	 */
	public function subtotal( array $package, string $currency_code ): Money {
		$minor = 0;

		foreach ( $this->contents( $package ) as $entry ) {
			$line = $this->number( $entry['line_total'] ?? 0 ) + $this->number( $entry['line_tax'] ?? 0 );

			$minor += (int) round( $line * 100 );
		}

		return Money::from_minor_units( $minor, $currency_code );
	}

	/**
	 * @param array<string, mixed> $package
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function contents( array $package ): array {
		$contents = $package['contents'] ?? array();

		return is_array( $contents ) ? $contents : array();
	}

	/**
	 * Читает одно измерение товара. Вызов динамический: у нестандартных типов
	 * товаров геттера может не быть, и это не повод ронять расчёт корзины.
	 */
	private function measure( object $product, string $getter ): float {
		if ( ! is_callable( array( $product, $getter ) ) ) {
			return 0.0;
		}

		return $this->number( $product->{$getter}() );
	}

	/**
	 * WooCommerce хранит вес и габариты строками, причём пустая строка
	 * означает «не задано».
	 */
	private function number( mixed $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
