<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\QuoteBuilder;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class QuoteBuilderTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, string>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->options = array(
			'ozon_delivery_client_id'          => 'id',
			'ozon_delivery_client_secret'      => 'secret',
			'woocommerce_weight_unit'          => 'kg',
			'woocommerce_dimension_unit'       => 'cm',
			Settings::FIELD_DEFAULT_WEIGHT     => '0.5',
			Settings::FIELD_DEFAULT_LENGTH     => '20',
			Settings::FIELD_DEFAULT_WIDTH      => '15',
			Settings::FIELD_DEFAULT_HEIGHT     => '10',
			Settings::FIELD_PACKAGING_PADDING  => '10',
			Settings::FIELD_DECLARED_PERCENT   => '100',
			Settings::FIELD_SHIPMENT_METHOD_ID => '777',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = '' ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function package( string $weight = '1.5', float $line_total = 2500.0 ): array {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_weight' )->andReturn( $weight );
		$product->shouldReceive( 'get_length' )->andReturn( '30' );
		$product->shouldReceive( 'get_width' )->andReturn( '20' );
		$product->shouldReceive( 'get_height' )->andReturn( '10' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		return array(
			'contents' => array(
				array(
					'data'       => $product,
					'quantity'   => 1,
					'line_total' => $line_total,
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function quote_response(): array {
		return self::json(
			array(
				'results' => array(
					array(
						'request_id' => 1,
						'posting'    => array(
							'estimated_delivery_cost'  => array(
								'amount'        => '350.00',
								'currency_code' => 'RUB',
							),
							'estimated_insurance_cost' => array(
								'amount'        => '25.00',
								'currency_code' => 'RUB',
							),
							'estimated_delivery_days'  => 3,
						),
					),
				),
			)
		);
	}

	public function test_shipment_method_id_comes_from_settings(): void {
		self::assertSame( 777, QuoteBuilder::create()->shipment_method_id() );
	}

	public function test_missing_shipment_method_id_is_zero(): void {
		unset( $this->options[ Settings::FIELD_SHIPMENT_METHOD_ID ] );

		self::assertSame( 0, QuoteBuilder::create()->shipment_method_id() );
	}

	public function test_parcel_uses_product_dimensions_and_padding(): void {
		$parcel = QuoteBuilder::create()->parcel( self::package() );

		self::assertSame( 1500, $parcel->weight_g );
		self::assertSame( 310, $parcel->length_mm );
	}

	/**
	 * Габариты по умолчанию заданы в единицах WooCommerce и должны быть
	 * переведены, а не взяты как есть.
	 */
	public function test_defaults_are_converted_from_woocommerce_units(): void {
		$parcel = QuoteBuilder::create()->parcel( array() );

		self::assertSame( 500, $parcel->weight_g );
		self::assertSame( 210, $parcel->length_mm );
	}

	public function test_declared_value_follows_the_configured_percentage(): void {
		$this->options[ Settings::FIELD_DECLARED_PERCENT ] = '50';

		$value = QuoteBuilder::create()->declared_value( self::package() );

		self::assertSame( '1250.00', $value->amount );
		self::assertSame( 'RUB', $value->currency_code );
	}

	public function test_quote_is_produced_for_a_pickup_point(): void {
		$this->queue( array( self::quote_response() ) );

		$quote = QuoteBuilder::create()->quote(
			self::package(),
			'+79000000000',
			Destination::point( 4242 )
		);

		self::assertTrue( $quote->available );
		self::assertSame( '375.00', $quote->total()?->amount );
	}

	public function test_quote_sends_the_packed_parcel(): void {
		$this->queue( array( self::quote_response() ) );

		QuoteBuilder::create()->quote( self::package(), '+79000000000', Destination::point( 4242 ) );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 1500, $sent['postings'][0]['dimensions']['weight_g'] );
		self::assertSame( 777, $sent['postings'][0]['shipment_method_id'] );
		self::assertSame( '2500.00', $sent['postings'][0]['declared_value']['amount'] );
	}

	/**
	 * Без настроенного метода доставки расчёт невозможен, и дёргать API незачем.
	 */
	public function test_quote_without_shipment_method_is_refused_offline(): void {
		unset( $this->options[ Settings::FIELD_SHIPMENT_METHOD_ID ] );

		$quote = QuoteBuilder::create()->quote(
			self::package(),
			'+79000000000',
			Destination::point( 4242 )
		);

		self::assertFalse( $quote->available );
		self::assertNotSame( '', $quote->message );
		self::assertSame( array(), $this->calls );
	}

	public function test_quote_without_phone_is_refused_offline(): void {
		$quote = QuoteBuilder::create()->quote( self::package(), '  ', Destination::point( 4242 ) );

		self::assertFalse( $quote->available );
		self::assertSame( array(), $this->calls );
	}
}
