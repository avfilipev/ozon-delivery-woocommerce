<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Admin\CatalogStatus;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class CatalogStatusTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	private int $total = 0;

	private ?string $synced_at = null;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();

		$this->options   = array();
		$this->total     = 0;
		$this->synced_at = null;

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				if ( str_contains( $sql, 'MAX(updated_at)' ) ) {
					return $this->synced_at;
				}

				return str_contains( $sql, 'is_active' ) ? (int) round( $this->total * 0.9 ) : $this->total;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function catalog_status(): CatalogStatus {
		return new CatalogStatus(
			new Repository(),
			new CatalogSync(
				new DeliveryPoints( ClientFactory::create() ),
				new Repository(),
				new Logger()
			)
		);
	}

	public function test_empty_catalogue_is_reported_as_never_synced(): void {
		$summary = $this->catalog_status()->summary();

		self::assertSame( 0, $summary['total'] );
		self::assertNull( $summary['last_synced_at'] );
		self::assertFalse( $summary['running'] );
	}

	public function test_counts_are_reported(): void {
		$this->total     = 1000;
		$this->synced_at = '2026-08-27 09:30:00';

		$summary = $this->catalog_status()->summary();

		self::assertSame( 1000, $summary['total'] );
		self::assertSame( 900, $summary['active'] );
		self::assertSame( '2026-08-27 09:30:00', $summary['last_synced_at'] );
	}

	public function test_running_sync_is_reported(): void {
		$this->options[ CatalogSync::STATE_OPTION ] = array(
			'cursor'     => 'cursor-5',
			'finished'   => false,
			'processed'  => 250,
			'started_at' => '2026-08-27 10:00:00',
		);

		$summary = $this->catalog_status()->summary();

		self::assertTrue( $summary['running'] );
		self::assertSame( 250, $summary['processed'] );
	}

	public function test_finished_sync_is_not_running(): void {
		$this->options[ CatalogSync::STATE_OPTION ] = array(
			'cursor'     => null,
			'finished'   => true,
			'processed'  => 1000,
			'started_at' => '2026-08-27 10:00:00',
		);

		self::assertFalse( $this->catalog_status()->summary()['running'] );
	}

	public function test_empty_catalogue_is_described_for_a_human(): void {
		$description = $this->catalog_status()->describe();

		self::assertStringContainsString( 'не загружен', mb_strtolower( $description ) );
	}

	public function test_filled_catalogue_mentions_the_numbers(): void {
		$this->total     = 1000;
		$this->synced_at = '2026-08-27 09:30:00';

		$description = $this->catalog_status()->describe();

		self::assertStringContainsString( '1000', $description );
		self::assertStringContainsString( '900', $description );
	}

	public function test_running_sync_is_described(): void {
		$this->total                                = 100;
		$this->options[ CatalogSync::STATE_OPTION ] = array(
			'cursor'     => 'cursor-5',
			'finished'   => false,
			'processed'  => 250,
			'started_at' => '2026-08-27 10:00:00',
		);

		self::assertStringContainsString( '250', $this->catalog_status()->describe() );
	}
}
