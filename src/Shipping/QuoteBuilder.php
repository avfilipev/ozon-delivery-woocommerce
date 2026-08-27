<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Support\Money;

/**
 * Единая точка расчёта: пакет WooCommerce плюс адрес — на выходе тариф.
 *
 * Собирает воедино чтение корзины, упаковку, объявленную стоимость и вызов
 * order/checkout, чтобы метод доставки оставался тонкой обвязкой.
 */
final class QuoteBuilder {

	public function __construct(
		private readonly PackageReader $reader,
		private readonly Packer $packer,
		private readonly DeclaredValue $declared_value,
		private readonly RateCalculator $calculator,
		private readonly int $shipment_method_id,
		private readonly string $currency_code
	) {
	}

	/**
	 * Собирает расчётчик по настройкам плагина и WooCommerce.
	 */
	public static function create(): self {
		$weight_unit    = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$dimension_unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

		// Габариты по умолчанию заданы в единицах WooCommerce — переводим их
		// здесь же, через единственное разрешённое место (правило 8).
		$fallback = Dimensions::from_units(
			(float) get_option( Settings::FIELD_DEFAULT_WEIGHT, '0' ),
			$weight_unit,
			(float) get_option( Settings::FIELD_DEFAULT_LENGTH, '0' ),
			(float) get_option( Settings::FIELD_DEFAULT_WIDTH, '0' ),
			(float) get_option( Settings::FIELD_DEFAULT_HEIGHT, '0' ),
			$dimension_unit
		);

		return new self(
			new PackageReader( $weight_unit, $dimension_unit ),
			new Packer( $fallback, (int) get_option( Settings::FIELD_PACKAGING_PADDING, '0' ) ),
			new DeclaredValue( (int) get_option( Settings::FIELD_DECLARED_PERCENT, '100' ) ),
			new RateCalculator( new Orders( ClientFactory::create() ), new Logger() ),
			(int) get_option( Settings::FIELD_SHIPMENT_METHOD_ID, '0' ),
			function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'RUB'
		);
	}

	public function shipment_method_id(): int {
		return $this->shipment_method_id;
	}

	/**
	 * @param array<string, mixed> $package Пакет доставки WooCommerce.
	 */
	public function parcel( array $package ): Dimensions {
		return $this->packer->pack( $this->reader->items( $package ) );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public function declared_value( array $package ): Money {
		return $this->declared_value->for_subtotal( $this->reader->subtotal( $package, $this->currency_code ) );
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public function quote(
		array $package,
		string $phone_number,
		Destination $destination,
		?string $cutoff_at = null
	): CheckoutQuote {
		if ( $this->shipment_method_id <= 0 ) {
			return CheckoutQuote::failed(
				'',
				'Не задан Shipment Method ID в настройках Ozon Доставки.'
			);
		}

		if ( '' === trim( $phone_number ) ) {
			return CheckoutQuote::failed(
				'',
				'Для расчёта доставки Ozon укажите номер телефона.'
			);
		}

		return $this->calculator->quote(
			$phone_number,
			$this->shipment_method_id,
			$this->parcel( $package ),
			$this->declared_value( $package ),
			$destination,
			$cutoff_at
		);
	}
}
