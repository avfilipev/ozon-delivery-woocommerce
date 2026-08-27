<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Checkout\PointPicker;
use Spoki\OzonDelivery\Tests\TestCase;

final class PointPickerTest extends TestCase {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $session = array();

	protected function setUp(): void {
		parent::setUp();

		$this->rows    = array();
		$this->session = array();

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->rows );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$session = Mockery::mock();
		$session->shouldReceive( 'get' )->andReturnUsing(
			fn( string $key, $default_value = null ) => $this->session[ $key ] ?? $default_value
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->session[ $key ] = $value;
			}
		);

		$woocommerce          = Mockery::mock();
		$woocommerce->session = $session;

		Functions\when( 'WC' )->justReturn( $woocommerce );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_POST );

		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $city = 'Москва' ): array {
		return array(
			'delivery_point_id'   => $id,
			'name'                => 'ПВЗ ' . $id,
			'full_address'        => 'г. ' . $city . ', ул. Тверская, д. ' . $id,
			'city'                => $city,
			'is_active'           => 1,
			'latitude'            => 55.75,
			'longitude'           => 37.61,
			'type'                => 'PICKUP_POINT',
			'storage_period_days' => 5,
		);
	}

	public function test_search_returns_points_for_a_city(): void {
		$this->rows = array( self::row( 1 ), self::row( 2 ) );

		$found = ( new PointPicker() )->search( 'Москва' );

		self::assertCount( 2, $found );
		self::assertSame( 1, $found[0]['id'] );
		self::assertSame( 'ПВЗ 1', $found[0]['name'] );
		self::assertSame( 'г. Москва, ул. Тверская, д. 1', $found[0]['address'] );
	}

	/**
	 * Координаты нужны карте, срок хранения — покупателю.
	 */
	public function test_search_result_carries_map_and_display_data(): void {
		$this->rows = array( self::row( 1 ) );

		$found = ( new PointPicker() )->search( 'Москва' );

		self::assertSame( 55.75, $found[0]['latitude'] );
		self::assertSame( 37.61, $found[0]['longitude'] );
		self::assertSame( 5, $found[0]['storage_period_days'] );
	}

	public function test_empty_query_returns_nothing(): void {
		$this->rows = array( self::row( 1 ) );

		self::assertSame( array(), ( new PointPicker() )->search( '   ' ) );
	}

	public function test_search_result_is_limited(): void {
		$this->rows = array();

		for ( $i = 1; $i <= 200; $i++ ) {
			$this->rows[] = self::row( $i );
		}

		self::assertLessThanOrEqual( PointPicker::MAX_RESULTS, count( ( new PointPicker() )->search( 'Москва' ) ) );
	}

	public function test_choosing_a_known_point_is_remembered(): void {
		$this->rows = array( self::row( 4242 ) );

		$chosen = ( new PointPicker() )->choose( 4242 );

		self::assertTrue( $chosen );
		self::assertSame( 4242, $this->session['ozon_delivery_point_id'] );
	}

	/**
	 * Точки могло не стать между показом списка и выбором.
	 */
	public function test_choosing_an_unknown_point_is_refused(): void {
		$this->rows = array();

		self::assertFalse( ( new PointPicker() )->choose( 9999 ) );
		self::assertArrayNotHasKey( 'ozon_delivery_point_id', $this->session );
	}

	public function test_choosing_zero_is_refused(): void {
		self::assertFalse( ( new PointPicker() )->choose( 0 ) );
	}

	public function test_chosen_point_can_be_read_back(): void {
		$this->rows = array( self::row( 4242 ) );

		$picker = new PointPicker();
		$picker->choose( 4242 );

		$chosen = $picker->chosen();

		self::assertIsArray( $chosen );
		self::assertSame( 4242, $chosen['id'] );
		self::assertSame( 'ПВЗ 4242', $chosen['name'] );
	}

	public function test_nothing_chosen_reads_back_as_null(): void {
		self::assertNull( ( new PointPicker() )->chosen() );
	}

	/**
	 * Точку могли удалить из каталога уже после выбора.
	 */
	public function test_chosen_point_missing_from_catalogue_reads_back_as_null(): void {
		$this->session['ozon_delivery_point_id'] = 4242;
		$this->rows                              = array();

		self::assertNull( ( new PointPicker() )->chosen() );
	}
}
