<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Checkout\CheckoutHooks;
use Spoki\OzonDelivery\Checkout\CustomerPhone;
use Spoki\OzonDelivery\Checkout\OrderQuote;
use Spoki\OzonDelivery\Checkout\SessionState;
use Spoki\OzonDelivery\Points\Repository;
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
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $value ) => $value );
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

	/**
	 * WooCommerce кэширует тарифы по хешу пакета доставки. Выбранный ПВЗ
	 * лежит в сессии, а не в пакете, поэтому без этого хеш не менялся: цена
	 * после выбора точки не появлялась вовсе, отдавался старый пустой ответ.
	 */
	public function test_chosen_point_goes_into_the_shipping_package(): void {
		$this->session['ozon_delivery_point_id'] = 4242;

		$packages = ( new CheckoutHooks() )->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		self::assertSame( 4242, $packages[0]['ozon_delivery_point_id'] );
	}

	public function test_different_points_give_different_packages(): void {
		$hooks = new CheckoutHooks();

		$this->session['ozon_delivery_point_id'] = 1;
		$first                                   = $hooks->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		$this->session['ozon_delivery_point_id'] = 2;
		$second                                  = $hooks->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		self::assertNotSame(
			wp_json_encode( $first ),
			wp_json_encode( $second ),
			'Иначе WooCommerce отдаст закэшированный тариф от прошлой точки.'
		);
	}

	/**
	 * Тариф зависит и от телефона: по нему проверяется, обслуживается ли
	 * покупатель Ozon. Смена телефона тоже обязана сбрасывать кэш.
	 */
	public function test_phone_goes_into_the_shipping_package(): void {
		$packages = ( new CheckoutHooks() )->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		self::assertArrayHasKey( 'ozon_delivery_phone', $packages[0] );
	}

	/**
	 * Телефон обязан попасть в пакет именно из формы чекаута.
	 *
	 * Пока он брался только из WC()->customer, в пакет всегда попадала пустая
	 * строка: обработчик пересчёта WooCommerce телефон в покупателя не
	 * переносит. Из-за этого метод доставки не показывался никогда — ни один
	 * тариф до покупателя не доходил.
	 */
	public function test_phone_from_the_checkout_form_reaches_the_package(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$_POST['post_data'] = 'billing_phone=%2B79001234567&billing_country=RU';

		$packages = ( new CheckoutHooks() )->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		unset( $_POST['post_data'] );

		self::assertSame( '+79001234567', $packages[0]['ozon_delivery_phone'] );
	}

	/**
	 * Смена телефона обязана менять отпечаток пакета, иначе WooCommerce
	 * отдаст тариф, посчитанный для прошлого покупателя.
	 */
	public function test_different_phones_give_different_packages(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$hooks = new CheckoutHooks();

		$_POST['post_data'] = 'billing_phone=%2B79000000001';
		$first              = $hooks->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		$_POST['post_data'] = 'billing_phone=%2B79000000002';
		$second             = $hooks->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		unset( $_POST['post_data'] );

		self::assertNotSame( wp_json_encode( $first ), wp_json_encode( $second ) );
	}

	public function test_every_package_gets_the_choice(): void {
		$this->session['ozon_delivery_point_id'] = 4242;

		$packages = ( new CheckoutHooks() )->add_choice_to_packages(
			array( array( 'contents' => array() ), array( 'contents' => array() ) )
		);

		self::assertSame( 4242, $packages[0]['ozon_delivery_point_id'] );
		self::assertSame( 4242, $packages[1]['ozon_delivery_point_id'] );
	}

	public function test_no_point_chosen_is_still_marked(): void {
		$packages = ( new CheckoutHooks() )->add_choice_to_packages( array( array( 'contents' => array() ) ) );

		self::assertArrayHasKey( 'ozon_delivery_point_id', $packages[0] );
		self::assertSame( 0, $packages[0]['ozon_delivery_point_id'] );
	}

	/**
	 * Разбивка расчёта обязана записываться при создании заказа.
	 *
	 * `Meta::save_quote()` был написан и покрыт тестами, но не вызывался
	 * ниоткуда — метабокс показывал три пустые строки вместо стоимости
	 * доставки, страховки и срока. Тест следит именно за связкой: код,
	 * который никто не зовёт, тестами не ловится.
	 */
	public function test_quote_is_recorded_when_the_order_is_created(): void {
		$this->rows                              = array( self::row( 4242 ) );
		$this->session['ozon_delivery_point_id'] = 4242;

		$order = $this->order();

		$quote = Mockery::mock( OrderQuote::class );
		$quote->shouldReceive( 'save' )->once()->with( $order, 4242 );

		( new CheckoutHooks( new SessionState(), new Repository(), new CustomerPhone(), $quote ) )
			->save_point_to_order( $order );

		self::assertSame( 4242, Meta::point_id( $order ) );
	}

	public function test_quote_is_not_recorded_without_a_point(): void {
		$quote = Mockery::mock( OrderQuote::class );
		$quote->shouldNotReceive( 'save' );

		( new CheckoutHooks( new SessionState(), new Repository(), new CustomerPhone(), $quote ) )
			->save_point_to_order( $this->order() );

		self::assertSame( array(), $this->meta );
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

		self::assertSame( 'Пункт выдачи не подходит.', $hooks->current_notice() );
		self::assertSame(
			'Пункт выдачи не подходит.',
			$hooks->current_notice(),
			'Объяснение держится, пока держится причина.'
		);
	}
}
