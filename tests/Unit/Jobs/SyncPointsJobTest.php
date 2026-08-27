<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Jobs;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Jobs\SyncPointsJob;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class SyncPointsJobTest extends TestCase {

	use WpHttpStubs;

	/**
	 * Разово поставленные задачи: [timestamp, hook, args, group].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $scheduled = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $recurring = array();

	/**
	 * @var string[]
	 */
	private array $unscheduled = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	private bool $has_scheduled = false;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->scheduled     = array();
		$this->recurring     = array();
		$this->unscheduled   = array();
		$this->options       = array();
		$this->has_scheduled = false;

		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql ) => $sql );
		$wpdb->shouldReceive( 'replace' )->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				if ( in_array( $name, array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ), true ) ) {
					return 'set';
				}

				return $this->options[ $name ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-08-27 10:00:00' );

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
				$this->scheduled[] = array(
					'timestamp' => $timestamp,
					'hook'      => $hook,
					'group'     => $group,
				);
				return 1;
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int {
				$this->recurring[] = array(
					'interval' => $interval,
					'hook'     => $hook,
					'group'    => $group,
				);
				return 1;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook ): void {
				$this->unscheduled[] = $hook;
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias(
			fn(): bool => $this->has_scheduled
		);

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function job(): SyncPointsJob {
		return new SyncPointsJob(
			new CatalogSync(
				new DeliveryPoints( ClientFactory::create() ),
				new Repository(),
				new Logger()
			),
			new Logger()
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_start_now_schedules_a_single_action(): void {
		$this->job()->start_now();

		self::assertCount( 1, $this->scheduled );
		self::assertSame( SyncPointsJob::HOOK, $this->scheduled[0]['hook'] );
		self::assertSame( SyncPointsJob::GROUP, $this->scheduled[0]['group'] );
	}

	public function test_start_now_resets_the_sync_state(): void {
		$this->options[ CatalogSync::STATE_OPTION ] = array(
			'cursor'   => 'cursor-9',
			'finished' => true,
		);

		$this->job()->start_now();

		self::assertFalse( $this->options[ CatalogSync::STATE_OPTION ]['finished'] );
		self::assertNull( $this->options[ CatalogSync::STATE_OPTION ]['cursor'] );
	}

	public function test_daily_schedule_is_registered_once(): void {
		SyncPointsJob::schedule_daily();

		self::assertCount( 1, $this->recurring );
		self::assertSame( DAY_IN_SECONDS, $this->recurring[0]['interval'] );
	}

	public function test_daily_schedule_is_not_duplicated(): void {
		$this->has_scheduled = true;

		SyncPointsJob::schedule_daily();

		self::assertSame( array(), $this->recurring );
	}

	public function test_unschedule_clears_the_hook(): void {
		SyncPointsJob::unschedule();

		self::assertContains( SyncPointsJob::HOOK, $this->unscheduled );
	}

	/**
	 * Шаг ограничен одной страницей, поэтому следующая ставится в очередь —
	 * так обход не упирается в лимит времени выполнения.
	 */
	public function test_unfinished_step_schedules_the_next_one(): void {
		$this->queue(
			array(
				self::json(
					array(
						'delivery_points' => array( array( 'delivery_point_id' => 1 ) ),
						'next_cursor'     => 'cursor-2',
					)
				),
				self::json( array( 'delivery_points' => array() ) ),
			)
		);

		$this->job()->run();

		self::assertCount( 1, $this->scheduled );
		self::assertSame( SyncPointsJob::HOOK, $this->scheduled[0]['hook'] );
	}

	public function test_finished_step_does_not_schedule_more_work(): void {
		$this->queue( array( self::json( array( 'delivery_points' => array() ) ) ) );

		$this->job()->run();

		self::assertSame( array(), $this->scheduled );
	}

	/**
	 * Ошибка API не должна ронять обработчик Action Scheduler: он бы отметил
	 * задачу упавшей и потерял расписание. Шаг просто ставится заново.
	 */
	public function test_api_failure_is_caught_and_the_step_is_retried(): void {
		$this->queue( array( self::response( 500, array(), '{}' ), self::response( 500, array(), '{}' ), self::response( 500, array(), '{}' ) ) );

		$before = time();

		$this->job()->run();

		self::assertCount( 1, $this->scheduled );
		self::assertGreaterThan( $before, $this->scheduled[0]['timestamp'], 'Повтор должен быть отложен, а не мгновенным.' );
	}
}
