<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Plugin;
use Spoki\OzonDelivery\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_boot_registers_hpos_hook_and_settings_page_filter(): void {
		Actions\expectAdded( 'before_woocommerce_init' )->once();
		Filters\expectAdded( 'woocommerce_get_settings_pages' )->once();

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->boot();

		$this->expectNotToPerformAssertions();
	}

	/**
	 * Без регистрации обработчика фоновый обход каталога никогда не выполнится:
	 * Action Scheduler поставит задачу, а слушать её будет некому.
	 */
	public function test_boot_registers_the_catalogue_sync_job(): void {
		Actions\expectAdded( 'ozon_delivery_sync_points' )->once();

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->boot();

		$this->expectNotToPerformAssertions();
	}

	/**
	 * Без этого метод доставки не появится в зонах WooCommerce.
	 */
	public function test_boot_registers_the_shipping_method(): void {
		Filters\expectAdded( 'woocommerce_shipping_methods' )->once();

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->boot();

		$this->expectNotToPerformAssertions();
	}

	/**
	 * Выбранный ПВЗ должен попасть в заказ при оформлении.
	 */
	public function test_boot_hooks_into_order_creation(): void {
		Actions\expectAdded( 'woocommerce_checkout_create_order' )->once();

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->boot();

		$this->expectNotToPerformAssertions();
	}

	public function test_activate_schedules_the_daily_catalogue_sync(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$GLOBALS['wpdb'] = $wpdb;

		$recurring = array();

		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( int $timestamp, int $interval, string $hook ) use ( &$recurring ): int {
				$recurring[] = $hook;
				return 1;
			}
		);

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->activate();

		unset( $GLOBALS['wpdb'] );

		self::assertContains( 'ozon_delivery_sync_points', $recurring );
	}

	public function test_deactivate_unschedules_background_work(): void {
		$unscheduled = array();

		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$unscheduled ): void {
				$unscheduled[] = $hook;
			}
		);

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->deactivate();

		self::assertContains( 'ozon_delivery_sync_points', $unscheduled );
	}

	public function test_declare_compatibility_declares_hpos_support_and_checkout_blocks_incompatibility(): void {
		$features_util = Mockery::mock( 'alias:Automattic\WooCommerce\Utilities\FeaturesUtil' );

		$features_util->shouldReceive( 'declare_compatibility' )
			->once()
			->with( 'custom_order_tables', '/plugin/ozon-delivery-for-woocommerce.php', true );

		$features_util->shouldReceive( 'declare_compatibility' )
			->once()
			->with( 'cart_checkout_blocks', '/plugin/ozon-delivery-for-woocommerce.php', false );

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->declare_compatibility();

		$this->expectNotToPerformAssertions();
	}

	public function test_activate_runs_migrations(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$GLOBALS['wpdb'] = $wpdb;

		$applied = array();

		Functions\when( 'dbDelta' )->alias(
			static function ( string $sql ) use ( &$applied ): array {
				$applied[] = $sql;
				return array();
			}
		);

		Functions\expect( 'get_option' )
			->once()
			->with( Migrations::OPTION_NAME, false )
			->andReturn( false );

		Functions\expect( 'update_option' )
			->once()
			->with( Migrations::OPTION_NAME, Migrations::CURRENT_VERSION );

		Functions\when( 'as_has_scheduled_action' )->justReturn( true );

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->activate();

		unset( $GLOBALS['wpdb'] );

		self::assertCount( 1, $applied );
	}
}
