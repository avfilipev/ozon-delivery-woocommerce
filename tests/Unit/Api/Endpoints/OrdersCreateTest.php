<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class OrdersCreateTest extends TestCase {

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
			'ozon_delivery_client_id'     => 'id',
			'ozon_delivery_client_secret' => 'secret',
			'ozon_delivery_dry_run'       => 'no',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = '' ) => $this->options[ $name ] ?? $default_value
		);

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function endpoint(): Orders {
		return new Orders( ClientFactory::create() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private function create( string $key = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11' ): \Spoki\OzonDelivery\Order\CreatedOrder {
		return $this->endpoint()->create(
			'wc-123',
			'+79000000000',
			'Иван Иванов',
			777,
			'Заказ №123',
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::point( 4242 ),
			$key
		);
	}

	public function test_create_sends_the_expected_payload(): void {
		$this->queue(
			array(
				self::json(
					array(
						'order_number' => 'OZN-1',
						'postings'     => array(),
					)
				),
			)
		);

		$this->create();

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/order/create', $this->calls[0]['url'] );
		self::assertSame( 'wc-123', $sent['order_external_id'] );
		self::assertSame( '+79000000000', $sent['recipient']['phone_number'] );
		self::assertSame( 'Иван Иванов', $sent['recipient']['full_name'] );
		self::assertSame( 'Заказ №123', $sent['postings'][0]['description'] );
		self::assertSame( 777, $sent['postings'][0]['shipment_method_id'] );
		self::assertSame( 'wc-123', $sent['postings'][0]['posting_external_id'] );
		self::assertSame( array( 'delivery_point_id' => 4242 ), $sent['delivery']['delivery_point'] );
	}

	/**
	 * Правило 4: без заголовка Idempotency-Key запрос уходить не должен.
	 */
	public function test_idempotency_key_is_sent_as_a_header(): void {
		$this->queue( array( self::json( array( 'order_number' => 'OZN-1' ) ) ) );

		$this->create( 'my-uuid-key' );

		self::assertSame( 'my-uuid-key', $this->calls[0]['args']['headers']['Idempotency-Key'] );
	}

	public function test_empty_idempotency_key_is_refused_offline(): void {
		$this->queue( array( self::json( array( 'order_number' => 'OZN-1' ) ) ) );

		$this->expectException( ApiException::class );

		$this->create( '' );
	}

	public function test_order_and_posting_numbers_are_read(): void {
		$this->queue(
			array(
				self::json(
					array(
						'order_number' => 'OZN-1',
						'postings'     => array(
							array(
								'request_id'          => 1,
								'posting_number'      => 'POST-1',
								'posting_external_id' => 'wc-123',
							),
						),
					)
				),
			)
		);

		$created = $this->create();

		self::assertTrue( $created->succeeded() );
		self::assertSame( 'OZN-1', $created->order_number );
		self::assertSame( 'POST-1', $created->first_posting_number() );
	}

	/**
	 * Правило 3: ошибка может лежать внутри 200 по конкретному отправлению.
	 */
	public function test_per_posting_error_is_read(): void {
		$this->queue(
			array(
				self::json(
					array(
						'order_number' => 'OZN-1',
						'postings'     => array(
							array(
								'request_id' => 1,
								'error'      => array(
									'code'    => 'DPRE',
									'message' => 'точка не подходит',
								),
							),
						),
					)
				),
			)
		);

		$created = $this->create();

		self::assertFalse( $created->succeeded() );
		self::assertSame( 'DPRE', $created->postings[0]->error_code );
		self::assertStringContainsString( 'точка не подходит', $created->error_message() );
	}

	/**
	 * Форма ответа в спеке описана как postings[], но правило 3 говорит про
	 * results[]. Разбираются оба — до проверки на живом ответе.
	 */
	public function test_results_shape_is_understood_too(): void {
		$this->queue(
			array(
				self::json(
					array(
						'order_number' => 'OZN-2',
						'results'      => array(
							array(
								'request_id'     => 1,
								'posting_number' => 'POST-2',
							),
						),
					)
				),
			)
		);

		$created = $this->create();

		self::assertTrue( $created->succeeded() );
		self::assertSame( 'POST-2', $created->first_posting_number() );
	}

	/**
	 * Заказ без номера — не успех, даже если HTTP 200.
	 */
	public function test_response_without_order_number_is_a_failure(): void {
		$this->queue( array( self::json( array( 'postings' => array() ) ) ) );

		$created = $this->create();

		self::assertFalse( $created->succeeded() );
		self::assertNotSame( '', $created->error_message() );
	}

	/**
	 * Песочницы у Ozon нет: в dry-run заказ создаваться не должен.
	 */
	public function test_dry_run_blocks_creation(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::json( array( 'order_number' => 'OZN-1' ) ) ) );

		$this->expectException( DryRunException::class );

		$this->create();
	}

	/**
	 * Курьеру нужен полный адрес, которого в версии 1 ещё нет: лучше внятный
	 * отказ, чем заведомо неполный запрос в боевой контур.
	 */
	public function test_courier_destination_is_refused_for_now(): void {
		$this->queue( array( self::json( array( 'order_number' => 'OZN-1' ) ) ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessageMatches( '/курьер/iu' );

		$this->endpoint()->create(
			'wc-123',
			'+79000000000',
			'Иван Иванов',
			777,
			'Заказ №123',
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::courier( 55.757, 37.615 ),
			'key'
		);
	}
}
