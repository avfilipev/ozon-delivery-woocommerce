<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Order\Creator;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class CreatorTest extends TestCase {

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
	 * @var string[]
	 */
	private array $notes = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->meta    = array();
		$this->notes   = array();
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
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11' );

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function order( bool $with_point = true ): object {
		if ( $with_point ) {
			$this->meta[ Meta::POINT_ID ] = 4242;
		}

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_weight' )->andReturn( '1.5' );
		$product->shouldReceive( 'get_length' )->andReturn( '30' );
		$product->shouldReceive( 'get_width' )->andReturn( '20' );
		$product->shouldReceive( 'get_height' )->andReturn( '10' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
		$item->shouldReceive( 'get_total' )->andReturn( '2500' );
		$item->shouldReceive( 'get_total_tax' )->andReturn( '0' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '123' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '+79000000000' );
		$order->shouldReceive( 'get_formatted_billing_full_name' )->andReturn( 'Иван Иванов' );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item ) );
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
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);

		return $order;
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
	private static function created(): array {
		return self::json(
			array(
				'order_number' => 'OZN-1',
				'postings'     => array(
					array(
						'request_id'     => 1,
						'posting_number' => 'POST-1',
					),
				),
			)
		);
	}

	public function test_order_is_pushed_and_numbers_are_saved(): void {
		$this->queue( array( self::created() ) );

		$order  = $this->order();
		$result = Creator::create()->push( $order );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'OZN-1', Meta::order_number( $order ) );
		self::assertSame( 'POST-1', Meta::posting_number( $order ) );
	}

	public function test_push_sends_the_parcel_from_the_order_items(): void {
		$this->queue( array( self::created() ) );

		Creator::create()->push( $this->order() );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 1500, $sent['postings'][0]['dimensions']['weight_g'] );
		self::assertSame( '2500.00', $sent['postings'][0]['declared_value']['amount'] );
		self::assertSame( 4242, $sent['delivery']['delivery_point']['delivery_point_id'] );
		self::assertSame( '123', $sent['order_external_id'] );
	}

	/**
	 * Правило 4: ключ идемпотентности берётся из меты и переживает повторы.
	 */
	public function test_idempotency_key_is_sent_and_stored(): void {
		$this->queue( array( self::created() ) );

		$order = $this->order();
		Creator::create()->push( $order );

		self::assertSame(
			'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
			$this->calls[0]['args']['headers']['Idempotency-Key']
		);
		self::assertSame( 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $this->meta[ Meta::IDEMPOTENCY_KEY ] );
	}

	public function test_successful_push_leaves_an_order_note(): void {
		$this->queue( array( self::created() ) );

		Creator::create()->push( $this->order() );

		self::assertNotSame( array(), $this->notes );
		self::assertStringContainsString( 'OZN-1', implode( ' ', $this->notes ) );
	}

	/**
	 * Уже переданный заказ повторно не отправляется: это защита от второго
	 * отправления даже до Idempotency-Key.
	 */
	public function test_already_pushed_order_is_not_sent_again(): void {
		$this->meta[ Meta::ORDER_NUMBER ] = 'OZN-1';

		$this->queue( array( self::created() ) );

		$result = Creator::create()->push( $this->order() );

		self::assertTrue( $result->succeeded() );
		self::assertSame( array(), $this->calls );
	}

	public function test_order_without_a_point_is_refused_offline(): void {
		$result = Creator::create()->push( $this->order( with_point: false ) );

		self::assertFalse( $result->succeeded() );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * Правило 3: ошибка внутри 200 попадает в мету и в примечание к заказу,
	 * а не теряется.
	 */
	public function test_per_posting_error_is_recorded_on_the_order(): void {
		$this->queue(
			array(
				self::json(
					array(
						'order_number' => 'OZN-1',
						'postings'     => array(
							array(
								'request_id' => 1,
								'error'      => array( 'code' => 'DPRE' ),
							),
						),
					)
				),
			)
		);

		$order  = $this->order();
		$result = Creator::create()->push( $order );

		self::assertFalse( $result->succeeded() );
		self::assertNotNull( Meta::error( $order ) );
		self::assertNull( Meta::posting_number( $order ) );
	}

	public function test_dry_run_is_reported_without_creating_anything(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::created() ) );

		$order  = $this->order();
		$result = Creator::create()->push( $order );

		self::assertFalse( $result->succeeded() );
		self::assertStringContainsString( 'dry-run', mb_strtolower( $result->error_message() ) );
		self::assertNull( Meta::order_number( $order ) );
	}

	/**
	 * Dry-run — настроенный режим, а не сбой заказа.
	 *
	 * Флаг ошибки в заказе метабокс показывает красной плашкой, а список
	 * заказов — как проблему, требующую разбирательства. Заказ, который
	 * намеренно не отправляли, так помечать нельзя: владелец магазина пошёл
	 * бы искать поломку там, где всё работает как задумано. Причина видна из
	 * примечания к заказу.
	 */
	public function test_dry_run_does_not_mark_the_order_as_failed(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$order = $this->order();

		Creator::create()->push( $order );

		self::assertNull( Meta::error( $order ), 'Dry-run не ошибка заказа.' );
		self::assertNotSame( array(), $this->notes, 'Но след остаться обязан.' );
		self::assertStringContainsString( 'dry-run', mb_strtolower( implode( ' ', $this->notes ) ) );
	}

	/**
	 * Отличать «намеренно не отправили» от «не получилось» должен сам
	 * результат: иначе вызывающий код — WP-CLI, метабокс — вынужден угадывать
	 * это по тексту сообщения. WP-CLI как раз и печатал на dry-run «Error» с
	 * кодом возврата 1, хотя ничего не ломалось.
	 */
	public function test_dry_run_result_is_marked_as_skipped(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$result = Creator::create()->push( $this->order() );

		self::assertTrue( $result->skipped );
		self::assertFalse( $result->succeeded() );
	}

	public function test_real_failure_is_not_marked_as_skipped(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		self::assertFalse( Creator::create()->push( $this->order() )->skipped );
	}

	/**
	 * Прошлая настоящая ошибка при этом не затирается: она про этот же заказ
	 * и по-прежнему требует внимания.
	 */
	public function test_dry_run_keeps_a_previous_real_error(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		$order = $this->order();
		Creator::create()->push( $order );

		$failed = Meta::error( $order );
		self::assertNotNull( $failed );

		$this->options['ozon_delivery_dry_run'] = 'yes';
		Creator::create()->push( $order );

		self::assertSame( $failed, Meta::error( $order ) );
	}

	public function test_api_failure_is_recorded_and_not_thrown(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		$order  = $this->order();
		$result = Creator::create()->push( $order );

		self::assertFalse( $result->succeeded() );
		self::assertNotNull( Meta::error( $order ) );
	}
}
