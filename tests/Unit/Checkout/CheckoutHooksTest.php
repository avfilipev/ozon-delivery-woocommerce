<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Checkout\CheckoutHooks;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Shipping\Methods;
use Spoki\OzonDelivery\Tests\TestCase;

final class CheckoutHooksTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private array $session = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * @var string[]
	 */
	private array $chosen_methods = array();

	protected function setUp(): void {
		parent::setUp();

		$this->session        = array();
		$this->meta           = array();
		$this->rows           = array();
		$this->chosen_methods = array( Methods::PICKUP );

		$session = Mockery::mock();
		$session->shouldReceive( 'get' )->andReturnUsing(
			function ( string $key, $default_value = null ) {
				if ( 'chosen_shipping_methods' === $key ) {
					return $this->chosen_methods;
				}

				return $this->session[ $key ] ?? $default_value;
			}
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->session[ $key ] = $value;
			}
		);

		$woocommerce          = Mockery::mock();
		$woocommerce->session = $session;

		Functions\when( 'WC' )->justReturn( $woocommerce );
		Functions\when( 'current_time' )->justReturn( '2026-08-27 10:00:00' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->rows );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
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
	private static function row( int $id = 4242 ): array {
		return array(
			'delivery_point_id' => $id,
			'name'              => 'ПВЗ на Тверской',
			'full_address'      => 'г. Москва, ул. Тверская, д. 1',
			'city'              => 'Москва',
			'is_active'         => 1,
		);
	}

	public function test_pickup_method_is_registered(): void {
		$methods = ( new CheckoutHooks() )->register_shipping_method( array( 'flat_rate' => 'WC_Shipping_Flat_Rate' ) );

		self::assertArrayHasKey( Methods::PICKUP, $methods );
		self::assertArrayHasKey( 'flat_rate', $methods, 'Чужие методы должны остаться.' );
	}

	public function test_chosen_point_is_saved_to_the_order(): void {
		$this->rows                              = array( self::row( 4242 ) );
		$this->session['ozon_delivery_point_id'] = 4242;

		$order = $this->order();

		( new CheckoutHooks() )->save_point_to_order( $order );

		self::assertSame( 4242, Meta::point_id( $order ) );
		self::assertSame( 'ПВЗ на Тверской', $this->meta[ Meta::POINT_NAME ] );
	}

	public function test_nothing_is_saved_when_no_point_was_chosen(): void {
		$order = $this->order();

		( new CheckoutHooks() )->save_point_to_order( $order );

		self::assertNull( Meta::point_id( $order ) );
	}

	/**
	 * Метод доставки могли переключить на другой — точка Ozon тогда не при чём.
	 */
	public function test_nothing_is_saved_when_another_method_is_chosen(): void {
		$this->chosen_methods                    = array( 'flat_rate' );
		$this->rows                              = array( self::row( 4242 ) );
		$this->session['ozon_delivery_point_id'] = 4242;

		$order = $this->order();

		( new CheckoutHooks() )->save_point_to_order( $order );

		self::assertNull( Meta::point_id( $order ) );
	}

	/**
	 * Точка могла исчезнуть из каталога между выбором и оформлением.
	 */
	public function test_unknown_point_is_not_saved(): void {
		$this->rows                              = array();
		$this->session['ozon_delivery_point_id'] = 9999;

		$order = $this->order();

		( new CheckoutHooks() )->save_point_to_order( $order );

		self::assertNull( Meta::point_id( $order ) );
	}

	/**
	 * Правило 5: сообщение об ошибке расчёта — это не wc_add_notice('error'),
	 * а своя строка, которую чекаут показывает и забывает.
	 */
	public function test_notice_is_returned_once_for_display(): void {
		$hooks = new CheckoutHooks();

		$this->session['ozon_delivery_notice'] = 'Пункт выдачи не подходит.';

		self::assertSame( 'Пункт выдачи не подходит.', $hooks->take_notice() );
		self::assertNull( $hooks->take_notice() );
	}
}
