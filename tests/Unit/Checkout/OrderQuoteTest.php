<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Checkout\OrderQuote;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

/**
 * Разбивка расчёта в заказе.
 *
 * Метабокс заказа показывает стоимость доставки, страховку и срок — и читал
 * три меты, которые в рабочем коде не записывал никто. `Meta::save_quote()`
 * был написан и покрыт тестами, но не вызывался ниоткуда: три строки в
 * админке оставались пустыми всегда.
 */
final class OrderQuoteTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var array<string, string>
	 */
	private array $options = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $packages = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->meta    = array();
		$this->options = array(
			'ozon_delivery_client_id'          => 'id',
			'ozon_delivery_client_secret'      => 'secret',
			'ozon_delivery_dry_run'            => 'no',
			'woocommerce_weight_unit'          => 'kg',
			'woocommerce_dimension_unit'       => 'cm',
			Settings::FIELD_SHIPMENT_METHOD_ID => '777',
			Settings::FIELD_DEFAULT_WEIGHT     => '0.5',
			Settings::FIELD_DEFAULT_LENGTH     => '20',
			Settings::FIELD_DEFAULT_WIDTH      => '15',
			Settings::FIELD_DEFAULT_HEIGHT     => '10',
			Settings::FIELD_DECLARED_PERCENT   => '100',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = '' ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';

		$this->packages = array(
			array(
				'contents'    => array(),
				'destination' => array( 'country' => 'RU' ),
			),
		);

		$this->stub_woocommerce();
	}

	private function stub_woocommerce(): void {
		$cart = Mockery::mock();
		$cart->shouldReceive( 'get_shipping_packages' )->andReturnUsing( fn() => $this->packages );

		$customer = Mockery::mock();
		$customer->shouldReceive( 'get_billing_phone' )->andReturn( '+79000000000' );

		$woocommerce           = Mockery::mock();
		$woocommerce->cart     = $cart;
		$woocommerce->customer = $customer;

		Functions\when( 'WC' )->justReturn( $woocommerce );
	}

	private function order(): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		);
		$order->shouldReceive( 'save' )->andReturn( 1 );

		return $order;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function quote_response(): array {
		return self::response(
			200,
			array(),
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'results' => array(
						array(
							'request_id' => 1,
							'posting'    => array(
								'estimated_delivery_cost'  => array(
									'amount'        => '143.00',
									'currency_code' => 'RUB',
								),
								'estimated_insurance_cost' => array(
									'amount'        => '15.00',
									'currency_code' => 'RUB',
								),
								'estimated_delivery_days'  => 5,
							),
						),
					),
				)
			)
		);
	}

	public function test_breakdown_lands_in_the_order(): void {
		$this->queue( array( self::quote_response() ) );

		$order = $this->order();

		( new OrderQuote() )->save( $order, 4242 );

		self::assertSame( '143.00', $this->meta[ Meta::DELIVERY_COST ] ?? null );
		self::assertSame( '15.00', $this->meta[ Meta::INSURANCE_COST ] ?? null );
		self::assertSame( 5, $this->meta[ Meta::DELIVERY_DAYS ] ?? null );
	}

	/**
	 * Оформление заказа не должно падать из-за расчёта: заказ уже принят и
	 * оплачен, а разбивка — сведения для админки. Правило 5 о том же.
	 */
	public function test_missing_cart_is_survivable(): void {
		$woocommerce = Mockery::mock();
		Functions\when( 'WC' )->justReturn( $woocommerce );

		$order = $this->order();

		( new OrderQuote() )->save( $order, 4242 );

		self::assertSame( array(), $this->meta );
	}

	public function test_no_packages_is_survivable(): void {
		$this->packages = array();

		( new OrderQuote() )->save( $this->order(), 4242 );

		self::assertSame( array(), $this->meta );
	}
}
