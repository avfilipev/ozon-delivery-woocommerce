<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\StatusSync;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class StatusSyncTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var string[]
	 */
	private array $notes = array();

	private string $order_status = 'processing';

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->meta         = array();
		$this->notes        = array();
		$this->order_status = 'processing';

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => in_array(
				$name,
				array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ),
				true
			) ? 'set' : $default_value
		);

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function order( string $posting_number = 'POST-1' ): object {
		if ( '' !== $posting_number ) {
			$this->meta[ Meta::POSTING_NUMBER ] = $posting_number;
		}

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( string $key ): void {
				unset( $this->meta[ $key ] );
			}
		);
		$order->shouldReceive( 'save' )->andReturn( 1 );
		$order->shouldReceive( 'get_status' )->andReturnUsing( fn() => $this->order_status );
		$order->shouldReceive( 'update_status' )->andReturnUsing(
			function ( string $status, string $note = '' ): bool {
				$this->order_status = $status;
				if ( '' !== $note ) {
					$this->notes[] = $note;
				}
				return true;
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);

		return $order;
	}

	private function sync(): StatusSync {
		return new StatusSync( new Postings( ClientFactory::create() ), new Logger() );
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
							'posting_number'    => 'POST-1',
							'status'            => $status,
							'status_changed_at' => '2026-08-27T10:00:00Z',
						),
					),
				)
			)
		);
	}

	public function test_status_is_stored_on_the_order(): void {
		$this->queue( array( self::info( 'ON_WAY' ) ) );

		$order = $this->order();
		$this->sync()->sync_order( $order );

		self::assertSame( 'ON_WAY', $this->meta[ Meta::POSTING_STATUS ] );
	}

	public function test_delivered_completes_the_order(): void {
		$this->queue( array( self::info( 'DELIVERED' ) ) );

		$this->sync()->sync_order( $this->order() );

		self::assertSame( 'completed', $this->order_status );
	}

	public function test_canceled_cancels_the_order(): void {
		$this->queue( array( self::info( 'CANCELED' ) ) );

		$this->sync()->sync_order( $this->order() );

		self::assertSame( 'cancelled', $this->order_status );
	}

	/**
	 * Ранние статусы ничего не говорят о судьбе заказа — трогать его статус
	 * не за что.
	 */
	public function test_early_status_does_not_touch_the_order_status(): void {
		$this->queue( array( self::info( 'CREATED' ) ) );

		$this->sync()->sync_order( $this->order() );

		self::assertSame( 'processing', $this->order_status );
	}

	public function test_status_change_is_noted_in_the_order(): void {
		$this->queue( array( self::info( 'IN_DELIVERY_POINT' ) ) );

		$this->sync()->sync_order( $this->order() );

		self::assertNotSame( array(), $this->notes );
	}

	/**
	 * Опрос идёт часто, а статус меняется редко: повторять одно и то же
	 * примечание в заказе незачем.
	 */
	public function test_unchanged_status_adds_no_new_note(): void {
		$this->queue( array( self::info( 'ON_WAY' ), self::info( 'ON_WAY' ) ) );

		$order = $this->order();

		$this->sync()->sync_order( $order );
		$notes_after_first = count( $this->notes );

		$this->sync()->sync_order( $order );

		self::assertCount( $notes_after_first, $this->notes );
	}

	/**
	 * Заказ без отправления опрашивать нечем.
	 */
	public function test_order_without_a_posting_is_skipped(): void {
		$result = $this->sync()->sync_order( $this->order( '' ) );

		self::assertNull( $result );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * Финальный статус больше не изменится — дальше опрашивать нечего.
	 */
	public function test_finished_posting_is_not_polled_again(): void {
		$this->meta[ Meta::POSTING_STATUS ] = 'DELIVERED';

		$result = $this->sync()->sync_order( $this->order() );

		self::assertNull( $result );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * Правило 5 по духу: сбой опроса не должен ломать админку и расписание.
	 */
	public function test_api_failure_does_not_throw(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		$result = $this->sync()->sync_order( $this->order() );

		self::assertNull( $result );
	}

	public function test_batch_syncs_every_order(): void {
		$this->queue( array( self::info( 'ON_WAY' ), self::info( 'ON_WAY' ) ) );

		$synced = $this->sync()->sync_orders( array( $this->order(), $this->order() ) );

		self::assertSame( 2, $synced );
	}
}
