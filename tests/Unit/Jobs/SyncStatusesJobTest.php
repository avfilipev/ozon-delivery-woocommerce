<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Jobs;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Jobs\SyncStatusesJob;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\StatusSync;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class SyncStatusesJobTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = array();

	/**
	 * @var object[]
	 */
	private array $orders = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $recurring = array();

	private bool $has_scheduled = false;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->queries       = array();
		$this->orders        = array();
		$this->meta          = array();
		$this->recurring     = array();
		$this->has_scheduled = false;

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => in_array(
				$name,
				array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ),
				true
			) ? 'set' : $default_value
		);
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ): array {
				$this->queries[] = $args;
				return $this->orders;
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $timestamp, int $interval, string $hook ): int {
				$this->recurring[] = array(
					'interval' => $interval,
					'hook'     => $hook,
				);
				return 1;
			}
		);
		Functions\when( 'as_has_scheduled_action' )->alias( fn(): bool => $this->has_scheduled );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function order(): object {
		$this->meta[ Meta::POSTING_NUMBER ] = 'POST-1';

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save' )->andReturn( 1 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'update_status' )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

		return $order;
	}

	private function job(): SyncStatusesJob {
		return new SyncStatusesJob(
			new StatusSync( new Postings( ClientFactory::create() ), new Logger() ),
			new Logger()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function info( string $status ): array {
		return self::response(
			200,
			array(),
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'postings' => array(
						array(
							'posting_number' => 'POST-1',
							'status'         => $status,
						),
					),
				)
			)
		);
	}

	/**
	 * Опрашивать вручённые и отменённые отправления бессмысленно — они уже
	 * не изменятся, а запросов к API это стоит.
	 */
	public function test_query_excludes_final_statuses(): void {
		$this->job()->run();

		self::assertCount( 1, $this->queries );

		$meta_query = $this->queries[0]['meta_query'];

		self::assertSame( Meta::POSTING_NUMBER, $meta_query[0]['key'] );
		self::assertSame( 'EXISTS', $meta_query[0]['compare'] );
		self::assertSame( 'NOT IN', $meta_query[1]['compare'] );
		self::assertSame( array( 'DELIVERED', 'CANCELED' ), $meta_query[1]['value'] );
	}

	public function test_query_is_limited(): void {
		$this->job()->run();

		self::assertGreaterThan( 0, $this->queries[0]['limit'] );
	}

	public function test_nothing_to_poll_makes_no_api_call(): void {
		$this->orders = array();

		$this->job()->run();

		self::assertSame( array(), $this->calls );
	}

	public function test_pending_order_is_polled_and_updated(): void {
		$this->orders = array( $this->order() );

		$this->queue( array( self::info( 'ON_WAY' ) ) );

		$this->job()->run();

		self::assertSame( 'ON_WAY', $this->meta[ Meta::POSTING_STATUS ] );
	}

	/**
	 * Выпущенное исключение пометило бы задачу Action Scheduler упавшей и
	 * оборвало расписание.
	 */
	public function test_failure_does_not_escape(): void {
		Functions\when( 'wc_get_orders' )->alias(
			static function (): array {
				throw new \RuntimeException( 'база недоступна' );
			}
		);

		$this->job()->run();

		$this->expectNotToPerformAssertions();
	}

	public function test_hourly_schedule_is_registered_once(): void {
		SyncStatusesJob::schedule_hourly();

		self::assertCount( 1, $this->recurring );
		self::assertSame( SyncStatusesJob::HOOK, $this->recurring[0]['hook'] );
		self::assertSame( HOUR_IN_SECONDS, $this->recurring[0]['interval'] );
	}

	public function test_hourly_schedule_is_not_duplicated(): void {
		$this->has_scheduled = true;

		SyncStatusesJob::schedule_hourly();

		self::assertSame( array(), $this->recurring );
	}
}
