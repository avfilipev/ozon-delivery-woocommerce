<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Shipping\CheckoutQuote;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class MetaTest extends TestCase {

	/**
	 * Мета «заказа» в памяти.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	private bool $saved = false;

	protected function setUp(): void {
		parent::setUp();

		$this->meta  = array();
		$this->saved = false;

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	private function order(): object {
		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			fn( string $key ) => $this->meta[ $key ] ?? ''
		);
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		);
		$order->shouldReceive( 'save' )->andReturnUsing(
			function (): int {
				$this->saved = true;
				return 1;
			}
		);

		return $order;
	}

	private static function point(): DeliveryPoint {
		return DeliveryPoint::from_api(
			array(
				'delivery_point_id' => 4242,
				'name'              => 'ПВЗ на Тверской',
				'full_address'      => 'г. Москва, ул. Тверская, д. 1',
				'is_active'         => true,
			)
		);
	}

	public function test_point_is_saved_with_readable_details(): void {
		$order = $this->order();

		Meta::save_point( $order, self::point() );

		self::assertSame( 4242, Meta::point_id( $order ) );
		self::assertSame( 'ПВЗ на Тверской', $this->meta[ Meta::POINT_NAME ] );
		self::assertSame( 'г. Москва, ул. Тверская, д. 1', $this->meta[ Meta::POINT_ADDRESS ] );
	}

	public function test_saving_the_point_persists_the_order(): void {
		Meta::save_point( $this->order(), self::point() );

		self::assertTrue( $this->saved );
	}

	public function test_order_without_a_point_returns_null(): void {
		self::assertNull( Meta::point_id( $this->order() ) );
	}

	/**
	 * Суммы хранятся строками — правило 9, никакой float-арифметики.
	 */
	public function test_quote_is_saved_as_strings(): void {
		$order = $this->order();

		Meta::save_quote(
			$order,
			new CheckoutQuote(
				true,
				new Money( '350.00', 'RUB' ),
				new Money( '25.00', 'RUB' ),
				3,
				'2026-08-28T12:00:00Z'
			)
		);

		self::assertSame( '350.00', $this->meta[ Meta::DELIVERY_COST ] );
		self::assertSame( '25.00', $this->meta[ Meta::INSURANCE_COST ] );
		self::assertIsString( $this->meta[ Meta::DELIVERY_COST ] );
		self::assertSame( 3, $this->meta[ Meta::DELIVERY_DAYS ] );
		self::assertSame( '2026-08-28T12:00:00Z', $this->meta[ Meta::CUTOFF_AT ] );
	}

	public function test_quote_without_insurance_is_saved(): void {
		$order = $this->order();

		Meta::save_quote( $order, new CheckoutQuote( true, new Money( '350.00', 'RUB' ) ) );

		self::assertSame( '350.00', $this->meta[ Meta::DELIVERY_COST ] );
		self::assertArrayNotHasKey( Meta::INSURANCE_COST, $this->meta );
	}

	/**
	 * Правило 5: ошибка расчёта показывается своей строкой в заказе, а не
	 * через wc_add_notice('error').
	 */
	public function test_failed_quote_is_recorded_as_an_error(): void {
		$order = $this->order();

		Meta::save_quote( $order, CheckoutQuote::failed( 'DPRE', 'Пункт выдачи не подходит.' ) );

		self::assertSame( 'Пункт выдачи не подходит.', Meta::error( $order ) );
		self::assertArrayNotHasKey( Meta::DELIVERY_COST, $this->meta );
	}

	public function test_successful_quote_clears_a_previous_error(): void {
		$order = $this->order();

		Meta::save_quote( $order, CheckoutQuote::failed( 'DPRE', 'Пункт выдачи не подходит.' ) );
		Meta::save_quote( $order, new CheckoutQuote( true, new Money( '350.00', 'RUB' ) ) );

		self::assertNull( Meta::error( $order ) );
	}

	public function test_ozon_order_and_posting_numbers_are_saved(): void {
		$order = $this->order();

		Meta::save_ozon_order( $order, 'OZN-1', 'POST-1' );

		self::assertSame( 'OZN-1', Meta::order_number( $order ) );
		self::assertSame( 'POST-1', Meta::posting_number( $order ) );
	}

	public function test_order_without_ozon_numbers_returns_null(): void {
		$order = $this->order();

		self::assertNull( Meta::order_number( $order ) );
		self::assertNull( Meta::posting_number( $order ) );
	}

	/**
	 * Ключи начинаются с подчёркивания: такая мета не показывается покупателю
	 * в списке произвольных полей.
	 *
	 * @dataProvider meta_key_provider
	 */
	public function test_meta_keys_are_private( string $key ): void {
		self::assertStringStartsWith( '_', $key );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function meta_key_provider(): array {
		return array(
			'id точки'    => array( Meta::POINT_ID ),
			'название'    => array( Meta::POINT_NAME ),
			'адрес'       => array( Meta::POINT_ADDRESS ),
			'стоимость'   => array( Meta::DELIVERY_COST ),
			'страховка'   => array( Meta::INSURANCE_COST ),
			'срок'        => array( Meta::DELIVERY_DAYS ),
			'cutoff'      => array( Meta::CUTOFF_AT ),
			'заказ Ozon'  => array( Meta::ORDER_NUMBER ),
			'отправление' => array( Meta::POSTING_NUMBER ),
			'ошибка'      => array( Meta::ERROR ),
		);
	}
}
