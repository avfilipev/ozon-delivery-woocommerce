<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Delivery;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class DeliveryTest extends TestCase {

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

	private function endpoint(): Delivery {
		return new Delivery( ClientFactory::create() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_check_client_asks_ozon_about_the_phone(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		$this->endpoint()->can_deliver_to( '+7 (900) 000-00-00' );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery/check-client', $this->calls[0]['url'] );
		self::assertArrayHasKey( 'phone_number', $sent );
	}

	public function test_registered_customer_can_be_delivered_to(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		self::assertTrue( $this->endpoint()->can_deliver_to( '+79000000000' ) );
	}

	/**
	 * Доставка доступна только покупателям, зарегистрированным на Ozon.
	 */
	public function test_unknown_customer_cannot_be_delivered_to(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => false ) ) ) );

		self::assertFalse( $this->endpoint()->can_deliver_to( '+79000000000' ) );
	}

	/**
	 * Ответ без поля трактуется как отказ: обещать доставку, которой может не
	 * быть, хуже, чем не показать метод.
	 */
	public function test_response_without_the_flag_is_treated_as_refusal(): void {
		$this->queue( array( self::json( array() ) ) );

		self::assertFalse( $this->endpoint()->can_deliver_to( '+79000000000' ) );
	}

	public function test_empty_phone_is_not_sent_to_the_api(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		self::assertFalse( $this->endpoint()->can_deliver_to( '   ' ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_location_sends_coordinates_and_methods(): void {
		$this->queue( array( self::json( array( 'results' => array() ) ) ) );

		$this->endpoint()->location( 55.757, 37.615, array( 100, 200 ) );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery/location', $this->calls[0]['url'] );
		self::assertSame(
			array(
				'latitude'  => 55.757,
				'longitude' => 37.615,
			),
			$sent['coordinates']
		);
		self::assertSame( 100, $sent['shipment_methods'][0]['shipment_method_id'] );
		self::assertCount( 2, $sent['shipment_methods'] );
	}

	public function test_location_returns_estimates_per_method(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'shipment_method_id'      => 100,
								'cutoff_at'               => '2026-08-28T12:00:00Z',
								'estimated_delivery_days' => 3,
							),
							array(
								'shipment_method_id' => 200,
								'cutoff_at'          => '2026-08-28T12:00:00Z',
								'error'              => array( 'code' => 'RE' ),
							),
						),
					)
				),
			)
		);

		$estimates = $this->endpoint()->location( 55.757, 37.615, array( 100, 200 ) );

		self::assertTrue( $estimates[100]->available );
		self::assertSame( 3, $estimates[100]->estimated_delivery_days );
		self::assertSame( '2026-08-28T12:00:00Z', $estimates[100]->cutoff_at );

		self::assertFalse( $estimates[200]->available );
		self::assertSame( 'RE', $estimates[200]->error_code );
		self::assertNotSame( '', $estimates[200]->message );
	}

	public function test_location_without_methods_makes_no_request(): void {
		$this->queue( array( self::json( array() ) ) );

		self::assertSame( array(), $this->endpoint()->location( 55.0, 37.0, array() ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_location_passes_cutoff_when_given(): void {
		$this->queue( array( self::json( array( 'results' => array() ) ) ) );

		$this->endpoint()->location( 55.757, 37.615, array( 100 ), '2026-08-28T12:00:00Z' );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( '2026-08-28T12:00:00Z', $sent['shipment_methods'][0]['cutoff_at'] );
	}
}
