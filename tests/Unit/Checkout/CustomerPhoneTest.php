<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Checkout\CustomerPhone;
use Spoki\OzonDelivery\Tests\TestCase;

/**
 * Телефон — единственное, по чему Ozon узнаёт покупателя, и без него метод
 * доставки не показывается вовсе.
 *
 * Проверено на живом чекауте: WC_AJAX::update_order_review() переносит в
 * покупателя только страну, регион, индекс, город и адрес. Телефона там нет,
 * поэтому WC()->customer->get_billing_phone() во время пересчёта пуст, хотя
 * форма его прислала — он лежит в post_data целой строкой.
 */
final class CustomerPhoneTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$_POST = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_clean' )->returnArg();

		$this->stub_customer( '' );
	}

	protected function tearDown(): void {
		$_POST = array();

		parent::tearDown();
	}

	private function stub_customer( string $phone ): void {
		$customer = Mockery::mock();
		$customer->shouldReceive( 'get_billing_phone' )->andReturn( $phone );

		$woocommerce           = Mockery::mock();
		$woocommerce->customer = $customer;

		Functions\when( 'WC' )->justReturn( $woocommerce );
	}

	public function test_reads_phone_from_the_serialised_checkout_form(): void {
		$_POST['post_data'] = 'billing_first_name=Иван&billing_phone=%2B7+900+123-45-67&billing_country=RU';

		self::assertSame( '+7 900 123-45-67', ( new CustomerPhone() )->resolve() );
	}

	/**
	 * Оформление заказа шлёт поля формы напрямую, без post_data.
	 */
	public function test_reads_phone_from_the_posted_field(): void {
		$_POST['billing_phone'] = '+79001234567';

		self::assertSame( '+79001234567', ( new CustomerPhone() )->resolve() );
	}

	public function test_posted_field_wins_over_the_serialised_form(): void {
		$_POST['billing_phone'] = '+79000000001';
		$_POST['post_data']     = 'billing_phone=%2B79000000002';

		self::assertSame( '+79000000001', ( new CustomerPhone() )->resolve() );
	}

	/**
	 * Обычная загрузка страницы: формы нет, но у покупателя телефон сохранён.
	 */
	public function test_falls_back_to_the_saved_customer_phone(): void {
		$this->stub_customer( '+79005550101' );

		self::assertSame( '+79005550101', ( new CustomerPhone() )->resolve() );
	}

	public function test_form_wins_over_the_saved_customer_phone(): void {
		$this->stub_customer( '+79005550101' );
		$_POST['post_data'] = 'billing_phone=%2B79009998877';

		self::assertSame( '+79009998877', ( new CustomerPhone() )->resolve() );
	}

	public function test_returns_empty_string_when_nothing_is_known(): void {
		self::assertSame( '', ( new CustomerPhone() )->resolve() );
	}

	public function test_ignores_a_form_without_the_phone_field(): void {
		$_POST['post_data'] = 'billing_first_name=Иван&billing_country=RU';

		self::assertSame( '', ( new CustomerPhone() )->resolve() );
	}

	public function test_phone_can_be_replaced_by_a_filter(): void {
		$_POST['post_data'] = 'billing_phone=%2B79001234567';

		Filters\expectApplied( 'ozon_delivery_customer_phone' )
			->once()
			->with( '+79001234567' )
			->andReturn( '+79007654321' );

		self::assertSame( '+79007654321', ( new CustomerPhone() )->resolve() );
	}
}
