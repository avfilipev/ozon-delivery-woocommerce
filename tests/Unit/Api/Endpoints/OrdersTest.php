<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class OrdersTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => in_array(
				$name,
				array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ),
				true
			) ? 'set' : $default_value
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
							'cutoff_at'                => '2026-08-28T12:00:00Z',
						),
					),
				),
			)
		);
	}

	private function checkout(): \Spoki\OzonDelivery\Shipping\CheckoutQuote {
		return $this->endpoint()->checkout(
			'+79000000000',
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::point( 4242 )
		);
	}

	public function test_checkout_sends_the_expected_payload(): void {
		$this->queue( array( self::quote_response() ) );

		$this->checkout();

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/order/checkout', $this->calls[0]['url'] );
		self::assertSame( '+79000000000', $sent['recipient']['phone_number'] );
		self::assertSame( 777, $sent['postings'][0]['shipment_method_id'] );
		self::assertSame( 1, $sent['postings'][0]['request_id'] );
		self::assertSame(
			array(
				'amount'        => '2500.00',
				'currency_code' => 'RUB',
			),
			$sent['postings'][0]['declared_value']
		);
		self::assertSame( 1200, $sent['postings'][0]['dimensions']['weight_g'] );
		self::assertSame( array( 'delivery_point_id' => 4242 ), $sent['delivery']['delivery_point'] );
	}

	public function test_checkout_returns_costs_as_money(): void {
		$this->queue( array( self::quote_response() ) );

		$quote = $this->checkout();

		self::assertTrue( $quote->available );
		self::assertSame( '350.00', $quote->delivery_cost?->amount );
		self::assertSame( '25.00', $quote->insurance_cost?->amount );
		self::assertSame( 3, $quote->estimated_delivery_days );
		self::assertSame( '2026-08-28T12:00:00Z', $quote->cutoff_at );
	}

	/**
	 * Правило 9: складывать деньги через float нельзя.
	 */
	public function test_total_sums_delivery_and_insurance_without_float(): void {
		$this->queue( array( self::quote_response() ) );

		$total = $this->checkout()->total();

		self::assertSame( '375.00', $total?->amount );
	}

	public function test_total_is_delivery_cost_when_there_is_no_insurance(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'request_id' => 1,
								'posting'    => array(
									'estimated_delivery_cost' => array(
										'amount'        => '350.00',
										'currency_code' => 'RUB',
									),
								),
							),
						),
					)
				),
			)
		);

		self::assertSame( '350.00', $this->checkout()->total()?->amount );
	}

	/**
	 * Правило 3: HTTP 200 не значит успех.
	 */
	public function test_error_inside_a_successful_response_is_read(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'request_id' => 1,
								'error'      => array(
									'code'    => 'DAE',
									'message' => 'не удалось рассчитать доставку',
								),
							),
						),
					)
				),
			)
		);

		$quote = $this->checkout();

		self::assertFalse( $quote->available );
		self::assertSame( 'DAE', $quote->error_code );
		self::assertStringContainsString( 'рассчитать', $quote->message );
		self::assertNull( $quote->delivery_cost );
		self::assertNull( $quote->total() );
	}

	/**
	 * Пустой results — не успех: расчёта нет, показывать нечего.
	 */
	public function test_empty_results_are_not_a_quote(): void {
		$this->queue( array( self::json( array( 'results' => array() ) ) ) );

		$quote = $this->checkout();

		self::assertFalse( $quote->available );
		self::assertNotSame( '', $quote->message );
	}

	public function test_courier_destination_is_supported(): void {
		$this->queue( array( self::quote_response() ) );

		$this->endpoint()->checkout(
			'+79000000000',
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::courier( 55.757, 37.615 )
		);

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 55.757, $sent['delivery']['courier']['coordinates']['latitude'] );
	}

	public function test_cutoff_is_passed_when_given(): void {
		$this->queue( array( self::quote_response() ) );

		$this->endpoint()->checkout(
			'+79000000000',
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::point( 4242 ),
			'2026-08-28T12:00:00Z'
		);

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( '2026-08-28T12:00:00Z', $sent['postings'][0]['cutoff_at'] );
	}
}
