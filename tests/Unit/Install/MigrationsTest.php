<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Install;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Tests\TestCase;

final class MigrationsTest extends TestCase {

	/**
	 * SQL, переданный в dbDelta.
	 *
	 * @var string[]
	 */
	private array $applied = array();

	protected function setUp(): void {
		parent::setUp();

		$this->applied = array();

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'dbDelta' )->alias(
			function ( string $sql ): array {
				$this->applied[] = $sql;
				return array();
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	public function test_run_stamps_current_version_on_fresh_install(): void {
		Functions\expect( 'get_option' )->once()->with( Migrations::OPTION_NAME, false )->andReturn( false );
		Functions\expect( 'update_option' )->once()->with( Migrations::OPTION_NAME, Migrations::CURRENT_VERSION );

		( new Migrations() )->run();

		self::assertCount( 1, $this->applied );
	}

	public function test_run_creates_the_delivery_points_table(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		( new Migrations() )->run();

		self::assertStringContainsString( 'CREATE TABLE wp_ozon_delivery_points', $this->applied[0] );
	}

	/**
	 * Поля, без которых не работают ни геовыборка, ни фильтр по ограничениям.
	 *
	 * @dataProvider required_column_provider
	 */
	public function test_table_has_the_columns_the_plugin_relies_on( string $column ): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		( new Migrations() )->run();

		self::assertStringContainsString( $column, $this->applied[0] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function required_column_provider(): array {
		return array(
			'идентификатор'      => array( 'delivery_point_id' ),
			'адрес'              => array( 'full_address' ),
			'город'              => array( 'city' ),
			'широта'             => array( 'latitude' ),
			'долгота'            => array( 'longitude' ),
			'активность'         => array( 'is_active' ),
			'методы доставки'    => array( 'shipment_method_ids' ),
			'минимальный вес'    => array( 'min_weight_g' ),
			'максимальный вес'   => array( 'max_weight_g' ),
			'мин. стоимость'     => array( 'min_price_minor' ),
			'макс. стоимость'    => array( 'max_price_minor' ),
			'валюта ограничений' => array( 'price_currency' ),
			'график'             => array( 'schedule' ),
			'время обновления'   => array( 'updated_at' ),
		);
	}

	public function test_run_updates_stale_version_to_current(): void {
		Functions\expect( 'get_option' )->once()->with( Migrations::OPTION_NAME, false )->andReturn( '1.0.0' );
		Functions\expect( 'update_option' )->once()->with( Migrations::OPTION_NAME, Migrations::CURRENT_VERSION );

		( new Migrations() )->run();

		self::assertCount( 1, $this->applied );
	}

	public function test_run_does_nothing_when_already_current(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Migrations::OPTION_NAME, false )
			->andReturn( Migrations::CURRENT_VERSION );

		Functions\expect( 'update_option' )->never();

		( new Migrations() )->run();

		self::assertSame( array(), $this->applied );
	}

	public function test_table_name_uses_the_wordpress_prefix(): void {
		self::assertSame( 'wp_ozon_delivery_points', Migrations::points_table() );
	}
}
