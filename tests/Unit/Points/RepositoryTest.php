<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Points;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Points\PointQuery;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class RepositoryTest extends TestCase {

	/**
	 * SQL, ушедший в get_results / get_var / query.
	 *
	 * @var string[]
	 */
	private array $queries = array();

	/**
	 * Аргументы, подставленные через prepare().
	 *
	 * @var array<int, mixed>
	 */
	private array $bound = array();

	/**
	 * Строки, которые вернёт get_results.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Данные, ушедшие в replace().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $replaced = array();

	private int $var_result = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->queries    = array();
		$this->bound      = array();
		$this->rows       = array();
		$this->replaced   = array();
		$this->var_result = 0;

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $value ) => $value );

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $sql, ...$args ) {
				$this->bound = array_merge( $this->bound, $args );
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) {
				$this->queries[] = $sql;
				return $this->rows;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				$this->queries[] = $sql;
				return $this->var_result;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'replace' )->andReturnUsing(
			function ( string $table, array $data ) {
				$this->replaced[] = $data;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'current_time' )->justReturn( '2026-08-27 10:00:00' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function last_query(): string {
		$last = end( $this->queries );

		return false === $last ? '' : $last;
	}

	private static function point( int $id = 1, string $city = 'Москва' ): DeliveryPoint {
		return DeliveryPoint::from_api(
			array(
				'delivery_point_id' => $id,
				'name'              => 'ПВЗ ' . $id,
				'full_address'      => 'г. ' . $city . ', ул. Тверская, д. 1',
				'is_active'         => true,
				'coordinates'       => array(
					'latitude'  => 55.75,
					'longitude' => 37.61,
				),
				'restrictions'      => array( 'max_weight_g' => 15000 ),
			),
			array( 100 )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( int $id = 1, string $city = 'Москва' ): array {
		return self::point( $id, $city )->to_row() + array( 'updated_at' => '2026-08-27 10:00:00' );
	}

	public function test_save_writes_the_point_with_a_timestamp(): void {
		( new Repository() )->save( self::point( 42 ) );

		self::assertCount( 1, $this->replaced );
		self::assertSame( 42, $this->replaced[0]['delivery_point_id'] );
		self::assertSame( '2026-08-27 10:00:00', $this->replaced[0]['updated_at'] );
	}

	public function test_save_many_writes_every_point(): void {
		$saved = ( new Repository() )->save_many( array( self::point( 1 ), self::point( 2 ) ) );

		self::assertSame( 2, $saved );
		self::assertCount( 2, $this->replaced );
	}

	public function test_save_many_with_nothing_touches_the_database(): void {
		$saved = ( new Repository() )->save_many( array() );

		self::assertSame( 0, $saved );
		self::assertSame( array(), $this->replaced );
	}

	public function test_find_returns_a_point(): void {
		$this->rows = array( self::row( 42 ) );

		$point = ( new Repository() )->find( 42 );

		self::assertInstanceOf( DeliveryPoint::class, $point );
		self::assertSame( 42, $point->delivery_point_id );
	}

	public function test_find_returns_null_when_absent(): void {
		$this->rows = array();

		self::assertNull( ( new Repository() )->find( 999 ) );
	}

	public function test_counts_are_read_from_the_table(): void {
		$this->var_result = 137;

		self::assertSame( 137, ( new Repository() )->count() );
		self::assertStringContainsString( 'wp_ozon_delivery_points', $this->last_query() );
	}

	public function test_active_count_filters_by_the_flag(): void {
		$this->var_result = 90;

		self::assertSame( 90, ( new Repository() )->count_active() );
		self::assertStringContainsString( 'is_active', $this->last_query() );
	}

	public function test_last_synced_at_is_read_from_the_table(): void {
		$this->var_result = 0;

		( new Repository() )->last_synced_at();

		self::assertStringContainsString( 'MAX(updated_at)', $this->last_query() );
	}

	/**
	 * Закрытые точки не показываются никогда.
	 */
	public function test_search_only_returns_active_points(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search( new PointQuery() );

		self::assertStringContainsString( 'is_active = 1', $this->last_query() );
	}

	/**
	 * Поиск по городу обязан находить и точки, у которых город в адресе
	 * вложен глубже.
	 *
	 * Новая Москва — «Россия, Москва, Марушкинское, Большое Покровское,
	 * Лесная улица, 16д»: город точки здесь действительно посёлок, разбор не
	 * ошибается. Но покупатель ищет «Москву», и по боевому каталогу таких
	 * точек 499 в Москве и 315 в Петербурге — двенадцатая и шестая часть
	 * города соответственно. Их не находил никто.
	 */
	public function test_search_by_city_also_matches_the_address(): void {
		$this->rows = array( self::row( 1 ) );

		( new Repository() )->search( new PointQuery( city: 'Москва' ) );

		self::assertStringContainsString( 'full_address', $this->last_query() );
	}

	/**
	 * Точки самого города идут первыми: иначе полсотни мест в списке займут
	 * пригороды, отсортированные по алфавиту.
	 */
	public function test_exact_city_matches_come_first(): void {
		$this->rows = array( self::row( 1 ) );

		( new Repository() )->search( new PointQuery( city: 'Москва' ) );

		self::assertMatchesRegularExpression( '/ORDER BY\s*\(?\s*city = /', $this->last_query() );
	}

	public function test_search_by_city_filters_by_city(): void {
		$this->rows = array( self::row() );

		$points = ( new Repository() )->search( new PointQuery( city: 'Москва' ) );

		self::assertStringContainsString( 'city', $this->last_query() );
		self::assertContains( 'Москва', $this->bound );
		self::assertCount( 1, $points );
		self::assertInstanceOf( DeliveryPoint::class, $points[0] );
	}

	public function test_search_in_bounding_box_filters_by_coordinates(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search(
			new PointQuery( min_latitude: 55.0, max_latitude: 56.0, min_longitude: 37.0, max_longitude: 38.0 )
		);

		$sql = $this->last_query();

		self::assertStringContainsString( 'latitude', $sql );
		self::assertStringContainsString( 'longitude', $sql );
		self::assertContains( 55.0, $this->bound );
		self::assertContains( 38.0, $this->bound );
	}

	/**
	 * Главный смысл фильтрации в SQL: не тащить в check-availability точки,
	 * которые заведомо не подходят.
	 */
	public function test_search_filters_by_parcel_weight_and_sides(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search(
			new PointQuery( parcel: new Dimensions( 1200, 300, 200, 100 ) )
		);

		$sql = $this->last_query();

		self::assertStringContainsString( 'min_weight_g', $sql );
		self::assertStringContainsString( 'max_weight_g', $sql );
		self::assertStringContainsString( 'max_length_mm', $sql );
		self::assertStringContainsString( 'max_width_mm', $sql );
		self::assertStringContainsString( 'max_height_mm', $sql );
		self::assertContains( 1200, $this->bound );
	}

	public function test_search_filters_by_declared_value(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search(
			new PointQuery( declared_value: new Money( '2500.00', 'RUB' ) )
		);

		$sql = $this->last_query();

		self::assertStringContainsString( 'min_price_minor', $sql );
		self::assertStringContainsString( 'max_price_minor', $sql );
		self::assertContains( 250000, $this->bound );
		self::assertContains( 'RUB', $this->bound );
	}

	public function test_search_filters_by_shipment_method(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search( new PointQuery( shipment_method_id: 777 ) );

		self::assertStringContainsString( 'FIND_IN_SET', $this->last_query() );
		self::assertContains( 777, $this->bound );
	}

	public function test_search_applies_a_limit(): void {
		$this->rows = array( self::row() );

		( new Repository() )->search( new PointQuery( limit: 25 ) );

		self::assertStringContainsString( 'LIMIT', $this->last_query() );
		self::assertContains( 25, $this->bound );
	}

	public function test_search_maps_rows_to_points(): void {
		$this->rows = array( self::row( 1 ), self::row( 2 ) );

		$points = ( new Repository() )->search( new PointQuery() );

		self::assertCount( 2, $points );
		self::assertSame( 1, $points[0]->delivery_point_id );
		self::assertSame( 2, $points[1]->delivery_point_id );
	}

	/**
	 * После полного обхода каталога точки, которых Ozon больше не отдаёт,
	 * должны исчезнуть — иначе покупатель выберет несуществующий ПВЗ.
	 */
	public function test_delete_stale_removes_points_not_seen_in_this_sync(): void {
		( new Repository() )->delete_stale( '2026-08-27 09:00:00' );

		$sql = $this->last_query();

		self::assertStringContainsString( 'DELETE', $sql );
		self::assertStringContainsString( 'updated_at', $sql );
		self::assertContains( '2026-08-27 09:00:00', $this->bound );
	}

	public function test_delete_all_clears_the_table(): void {
		( new Repository() )->delete_all();

		self::assertStringContainsString( 'DELETE', $this->last_query() );
	}
}
