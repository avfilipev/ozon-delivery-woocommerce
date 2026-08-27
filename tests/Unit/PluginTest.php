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

		( new Plugin( '/plugin/ozon-delivery-for-woocommerce.php' ) )->activate();

		unset( $GLOBALS['wpdb'] );

		self::assertCount( 1, $applied );
	}
}
