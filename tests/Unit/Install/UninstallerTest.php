<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Install;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Install\Uninstaller;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Tests\TestCase;

final class UninstallerTest extends TestCase {

	/**
	 * @var string[]
	 */
	private array $deleted = array();

	/**
	 * @var string[]
	 */
	private array $dropped = array();

	protected function setUp(): void {
		parent::setUp();

		$this->deleted = array();
		$this->dropped = array();

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ): int {
				$this->dropped[] = $sql;
				return 0;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'delete_option' )->alias(
			function ( string $option ): bool {
				$this->deleted[] = $option;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				$this->deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * Список опций выводится из Settings, а не дублируется: иначе новая
	 * настройка однажды останется в базе после удаления плагина.
	 *
	 * @dataProvider settings_field_provider
	 */
	public function test_every_settings_field_is_deleted( string $field ): void {
		( new Uninstaller() )->run();

		self::assertContains( $field, $this->deleted );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function settings_field_provider(): array {
		$cases = array();

		foreach ( ( new Settings() )->get_fields() as $field ) {
			$cases[ $field['id'] ] = array( $field['id'] );
		}

		return $cases;
	}

	public function test_schema_version_is_deleted(): void {
		( new Uninstaller() )->run();

		self::assertContains( Migrations::OPTION_NAME, $this->deleted );
	}

	public function test_sync_state_is_deleted(): void {
		( new Uninstaller() )->run();

		self::assertContains( CatalogSync::STATE_OPTION, $this->deleted );
	}

	/**
	 * Кэш токена и cookie testcookie тоже не должны пережить удаление плагина.
	 */
	public function test_cached_secrets_are_deleted(): void {
		( new Uninstaller() )->run();

		self::assertContains( 'ozon_delivery_access_token', $this->deleted );
		self::assertContains( 'ozon_delivery_testcookie', $this->deleted );
	}

	public function test_delivery_points_table_is_dropped(): void {
		( new Uninstaller() )->run();

		self::assertCount( 1, $this->dropped );
		self::assertStringContainsString( 'DROP TABLE', $this->dropped[0] );
		self::assertStringContainsString( 'wp_ozon_delivery_points', $this->dropped[0] );
	}

	public function test_background_jobs_are_unscheduled(): void {
		$unscheduled = array();

		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook ) use ( &$unscheduled ): void {
				$unscheduled[] = $hook;
			}
		);

		( new Uninstaller() )->run();

		self::assertContains( 'ozon_delivery_sync_points', $unscheduled );
	}
}
