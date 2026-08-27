<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;
use Brain\Monkey\Functions;

final class DeliveryPointsTest extends TestCase {

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

	private function endpoint(): DeliveryPoints {
		return new DeliveryPoints( ClientFactory::create() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_list_page_requests_the_catalogue_with_a_limit(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->endpoint()->list_page( null, 100 );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery-point/list', $this->calls[0]['url'] );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( array( 'limit' => 100 ), $sent['pagination'] );
	}

	/**
	 * Ozon отвечает 400, если limit больше 100: «Размер страницы должен быть
	 * от 1 до 100». В docs/API.md предел не указан — выяснилось на боевом API.
	 */
	public function test_page_size_is_clamped_to_the_allowed_range(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->endpoint()->list_page( null, 500 );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( DeliveryPoints::MAX_PAGE_SIZE, $sent['pagination']['limit'] );
	}

	public function test_page_size_below_one_is_clamped(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->endpoint()->list_page( null, 0 );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 1, $sent['pagination']['limit'] );
	}

	public function test_default_page_size_is_within_the_limit(): void {
		self::assertLessThanOrEqual( DeliveryPoints::MAX_PAGE_SIZE, DeliveryPoints::DEFAULT_PAGE_SIZE );
	}

	public function test_list_page_passes_the_cursor_when_continuing(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->endpoint()->list_page( 'cursor-2', 100 );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'cursor-2', $sent['pagination']['cursor'] );
	}

	public function test_list_page_returns_ids_and_methods(): void {
		$this->queue(
			array(
				self::json(
					array(
						'delivery_points' => array(
							array(
								'delivery_point_id'   => 1,
								'shipment_method_ids' => array( 10, 20 ),
							),
							array(
								'delivery_point_id'   => 2,
								'shipment_method_ids' => array( 10 ),
							),
						),
						'next_cursor'     => 'cursor-3',
					)
				),
			)
		);

		$page = $this->endpoint()->list_page();

		self::assertSame( array( 1, 2 ), $page->ids() );
		self::assertSame( array( 10, 20 ), $page->shipment_method_ids_for( 1 ) );
		self::assertSame( 'cursor-3', $page->next_cursor );
		self::assertFalse( $page->is_last() );
	}

	public function test_page_without_next_cursor_is_the_last_one(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$page = $this->endpoint()->list_page();

		self::assertTrue( $page->is_last() );
		self::assertSame( array(), $page->ids() );
	}

	public function test_info_requests_details_for_the_given_ids(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->endpoint()->info( array( 1, 2, 3 ) );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery-point/info', $this->calls[0]['url'] );
		self::assertSame( array( 1, 2, 3 ), $sent['delivery_point_ids'] );
	}

	public function test_info_returns_delivery_point_objects(): void {
		$this->queue(
			array(
				self::json(
					array(
						'delivery_points' => array(
							array(
								'delivery_point_id' => 42,
								'name'              => 'ПВЗ на Тверской',
								'full_address'      => 'г. Москва, ул. Тверская, д. 1',
								'is_active'         => true,
							),
						),
					)
				),
			)
		);

		$points = $this->endpoint()->info( array( 42 ) );

		self::assertCount( 1, $points );
		self::assertSame( 42, $points[0]->delivery_point_id );
		self::assertSame( 'ПВЗ на Тверской', $points[0]->name );
		self::assertSame( 'Москва', $points[0]->city );
	}

	public function test_info_with_no_ids_makes_no_request(): void {
		$this->queue( array( self::json( array() ) ) );

		self::assertSame( array(), $this->endpoint()->info( array() ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_check_availability_sends_the_parcel(): void {
		$this->queue( array( self::json( array( 'results' => array() ) ) ) );

		$this->endpoint()->check_availability(
			array( 1, 2 ),
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' )
		);

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery-point/check-availability', $this->calls[0]['url'] );
		self::assertSame( array( 1, 2 ), $sent['delivery_point_ids'] );
		self::assertSame( 777, $sent['shipment_method_id'] );
		self::assertSame(
			array(
				'weight_g'  => 1200,
				'length_mm' => 300,
				'width_mm'  => 200,
				'height_mm' => 100,
			),
			$sent['postings'][0]['dimensions']
		);
		self::assertSame(
			array(
				'amount'        => '2500.00',
				'currency_code' => 'RUB',
			),
			$sent['postings'][0]['declared_value']
		);
		self::assertArrayHasKey( 'request_id', $sent['postings'][0] );
	}

	/**
	 * Правило 3: HTTP 200 не значит успех — ошибки лежат в results[].error.
	 */
	public function test_check_availability_reads_per_point_errors(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'request_id'        => 1,
								'delivery_point_id' => 1,
								'cutoff_at'         => '2026-08-28T12:00:00Z',
							),
							array(
								'request_id'        => 1,
								'delivery_point_id' => 2,
								'error'             => array(
									'code'    => 'DPRE',
									'message' => 'пункт не подходит',
								),
							),
						),
					)
				),
			)
		);

		$results = $this->endpoint()->check_availability(
			array( 1, 2 ),
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' )
		);

		self::assertTrue( $results[1]->available );
		self::assertSame( '2026-08-28T12:00:00Z', $results[1]->cutoff_at );

		self::assertFalse( $results[2]->available );
		self::assertSame( 'DPRE', $results[2]->error_code );
		self::assertNotSame( '', $results[2]->message );
	}

	public function test_check_availability_without_points_makes_no_request(): void {
		$this->queue( array( self::json( array() ) ) );

		$results = $this->endpoint()->check_availability(
			array(),
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' )
		);

		self::assertSame( array(), $results );
		self::assertSame( array(), $this->calls );
	}

	public function test_available_ids_helper_returns_only_usable_points(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'request_id'        => 1,
								'delivery_point_id' => 1,
							),
							array(
								'request_id'        => 1,
								'delivery_point_id' => 2,
								'error'             => array( 'code' => 'DAE' ),
							),
							array(
								'request_id'        => 1,
								'delivery_point_id' => 3,
							),
						),
					)
				),
			)
		);

		$results = $this->endpoint()->check_availability(
			array( 1, 2, 3 ),
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' )
		);

		$available = array_keys( array_filter( $results, static fn( $r ) => $r->available ) );

		self::assertSame( array( 1, 3 ), $available );
	}
}
