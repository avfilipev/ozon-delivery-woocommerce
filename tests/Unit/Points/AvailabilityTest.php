<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Points;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Points\Availability;
use Spoki\OzonDelivery\Points\PointQuery;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class AvailabilityTest extends TestCase {

	use WpHttpStubs;

	/**
	 * Строки, которые вернёт локальный каталог.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->rows = array();

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->rows );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => in_array(
				$name,
				array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ),
				true
			) ? 'set' : $default_value
		);
		Functions\when( 'current_time' )->justReturn( '2026-08-27 10:00:00' );

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id ): array {
		return array(
			'delivery_point_id'   => $id,
			'name'                => 'ПВЗ ' . $id,
			'full_address'        => 'г. Москва, ул. Тверская, д. ' . $id,
			'city'                => 'Москва',
			'is_active'           => 1,
			'latitude'            => 55.75,
			'longitude'           => 37.61,
			'shipment_method_ids' => '100',
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
	 * @param array<int, bool> $by_id delivery_point_id => доступна ли точка.
	 * @return array<string, mixed>
	 */
	private static function availability_response( array $by_id ): array {
		$results = array();

		foreach ( $by_id as $id => $available ) {
			$result = array(
				'request_id'        => 1,
				'delivery_point_id' => $id,
			);

			if ( ! $available ) {
				$result['error'] = array( 'code' => 'DPRE' );
			}

			$results[] = $result;
		}

		return self::json( array( 'results' => $results ) );
	}

	private function availability(): Availability {
		return new Availability(
			new Repository(),
			new DeliveryPoints( ClientFactory::create() ),
			new Logger()
		);
	}

	private static function parcel(): Dimensions {
		return new Dimensions( 1200, 300, 200, 100 );
	}

	private static function value(): Money {
		return new Money( '2500.00', 'RUB' );
	}

	public function test_returns_only_points_confirmed_by_ozon(): void {
		$this->rows = array( self::row( 1 ), self::row( 2 ), self::row( 3 ) );

		$this->queue(
			array(
				self::availability_response(
					array(
						1 => true,
						2 => false,
						3 => true,
					)
				),
			)
		);

		$points = $this->availability()->find(
			new PointQuery( city: 'Москва' ),
			100,
			self::parcel(),
			self::value()
		);

		self::assertSame( array( 1, 3 ), array_map( static fn( $p ) => $p->delivery_point_id, $points ) );
	}

	/**
	 * Точку, о которой Ozon вообще ничего не сказал, показывать нельзя:
	 * молчание — не подтверждение.
	 */
	public function test_point_missing_from_the_response_is_dropped(): void {
		$this->rows = array( self::row( 1 ), self::row( 2 ) );

		$this->queue( array( self::availability_response( array( 1 => true ) ) ) );

		$points = $this->availability()->find(
			new PointQuery(),
			100,
			self::parcel(),
			self::value()
		);

		self::assertSame( array( 1 ), array_map( static fn( $p ) => $p->delivery_point_id, $points ) );
	}

	/**
	 * Если локальный фильтр ничего не оставил, в API идти незачем.
	 */
	public function test_empty_local_catalogue_makes_no_api_call(): void {
		$this->rows = array();

		$points = $this->availability()->find(
			new PointQuery( city: 'Норильск' ),
			100,
			self::parcel(),
			self::value()
		);

		self::assertSame( array(), $points );
		self::assertSame( array(), $this->calls );
	}

	public function test_local_filter_receives_the_parcel_and_value(): void {
		$this->rows = array( self::row( 1 ) );

		$this->queue( array( self::availability_response( array( 1 => true ) ) ) );

		$this->availability()->find(
			new PointQuery( city: 'Москва' ),
			100,
			self::parcel(),
			self::value()
		);

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 100, $sent['shipment_method_id'] );
		self::assertSame( 1200, $sent['postings'][0]['dimensions']['weight_g'] );
	}

	public function test_single_point_can_be_verified(): void {
		$this->rows = array( self::row( 7 ) );

		$this->queue( array( self::availability_response( array( 7 => true ) ) ) );

		self::assertTrue(
			$this->availability()->is_available( 7, 100, self::parcel(), self::value() )
		);
	}

	public function test_rejected_point_is_not_available(): void {
		$this->rows = array( self::row( 7 ) );

		$this->queue( array( self::availability_response( array( 7 => false ) ) ) );

		self::assertFalse(
			$this->availability()->is_available( 7, 100, self::parcel(), self::value() )
		);
	}

	/**
	 * Причина отказа нужна, чтобы показать её в заказе, а не молча спрятать точку.
	 */
	public function test_rejection_reason_is_available_to_the_caller(): void {
		$this->rows = array( self::row( 7 ) );

		$this->queue( array( self::availability_response( array( 7 => false ) ) ) );

		$reason = $this->availability()->rejection_reason( 7, 100, self::parcel(), self::value() );

		self::assertIsString( $reason );
		self::assertNotSame( '', $reason );
	}

	public function test_confirmed_point_has_no_rejection_reason(): void {
		$this->rows = array( self::row( 7 ) );

		$this->queue( array( self::availability_response( array( 7 => true ) ) ) );

		self::assertNull(
			$this->availability()->rejection_reason( 7, 100, self::parcel(), self::value() )
		);
	}
}
