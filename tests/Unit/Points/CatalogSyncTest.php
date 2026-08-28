<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Points;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class CatalogSyncTest extends TestCase {

	use WpHttpStubs;

	/**
	 * Точки, «сохранённые» в каталог.
	 *
	 * @var int[]
	 */
	private array $saved = array();

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	private ?string $deleted_before = null;

	/**
	 * Аргументы последнего prepare().
	 *
	 * @var array<int, mixed>
	 */
	private array $prepared_args = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->saved          = array();
		$this->options        = array();
		$this->deleted_before = null;
		$this->prepared_args  = array();

		$this->stub_wpdb();

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
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-08-27 10:00:00' );

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * Настоящий Repository поверх подменённого $wpdb: так проверяется и связка
	 * с ним, а не только договорённости мока.
	 */
	private function stub_wpdb(): void {
		$wpdb         = \Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $value ) => $value );

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $sql, ...$args ) {
				$this->prepared_args = $args;
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'replace' )->andReturnUsing(
			function ( string $table, array $data ): int {
				$this->saved[] = (int) $data['delivery_point_id'];
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ): int {
				if ( str_contains( $sql, 'DELETE' ) ) {
					$this->deleted_before = $this->prepared_args[0] ?? null;
				}
				return 0;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 );

		$GLOBALS['wpdb'] = $wpdb;
	}

	private function sync(): CatalogSync {
		return new CatalogSync(
			new DeliveryPoints( ClientFactory::create() ),
			new Repository(),
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

	/**
	 * @param int[] $ids
	 * @return array<string, mixed>
	 */
	private static function list_response( array $ids, ?string $next_cursor ): array {
		$points = array();

		foreach ( $ids as $id ) {
			$points[] = array(
				'delivery_point_id'   => $id,
				'shipment_method_ids' => array( 100 ),
			);
		}

		$payload = array( 'delivery_points' => $points );

		if ( null !== $next_cursor ) {
			$payload['next_cursor'] = $next_cursor;
		}

		return self::json( $payload );
	}

	/**
	 * @param int[] $ids
	 * @return array<string, mixed>
	 */
	private static function info_response( array $ids ): array {
		$points = array();

		foreach ( $ids as $id ) {
			$points[] = array(
				'delivery_point_id' => $id,
				'name'              => 'ПВЗ ' . $id,
				'full_address'      => 'г. Москва, ул. Тверская, д. 1',
				'is_active'         => true,
			);
		}

		return self::json( array( 'delivery_points' => $points ) );
	}

	public function test_first_step_asks_for_the_catalogue_from_the_beginning(): void {
		$this->queue(
			array(
				self::list_response( array( 1, 2 ), 'cursor-2' ),
				self::info_response( array( 1, 2 ) ),
			)
		);

		$this->sync()->run_step();

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertArrayNotHasKey( 'cursor', $sent['pagination'] );
	}

	public function test_step_saves_the_points_it_fetched(): void {
		$this->queue(
			array(
				self::list_response( array( 1, 2 ), 'cursor-2' ),
				self::info_response( array( 1, 2 ) ),
			)
		);

		$this->sync()->run_step();

		self::assertSame( array( 1, 2 ), $this->saved );
	}

	public function test_step_remembers_the_next_cursor(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), 'cursor-2' ),
				self::info_response( array( 1 ) ),
			)
		);

		$state = $this->sync()->run_step();

		self::assertSame( 'cursor-2', $state->cursor );
		self::assertFalse( $state->finished );
	}

	/**
	 * Ключевой сценарий из плана: обрыв на середине курсора должен
	 * продолжаться с того же места, а не начинаться заново.
	 */
	public function test_interrupted_sync_resumes_from_the_stored_cursor(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), 'cursor-2' ),
				self::info_response( array( 1 ) ),
			)
		);

		$this->sync()->run_step();

		// Новый процесс: объекты пересоздаются, состояние берётся из опции.
		$this->calls = array();

		$this->queue(
			array(
				self::list_response( array( 2 ), null ),
				self::info_response( array( 2 ) ),
			)
		);

		$this->sync()->run_step();

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'cursor-2', $sent['pagination']['cursor'] );
	}

	public function test_last_page_finishes_the_sync(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), null ),
				self::info_response( array( 1 ) ),
			)
		);

		$state = $this->sync()->run_step();

		self::assertTrue( $state->finished );
		self::assertNull( $state->cursor );
	}

	/**
	 * Точки, которых Ozon больше не отдаёт, убираются только после полного
	 * обхода: иначе обрыв на середине выкосит половину каталога.
	 */
	public function test_stale_points_are_removed_only_after_a_full_pass(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), 'cursor-2' ),
				self::info_response( array( 1 ) ),
			)
		);

		$this->sync()->run_step();

		self::assertNull( $this->deleted_before );

		$this->queue(
			array(
				self::list_response( array( 2 ), null ),
				self::info_response( array( 2 ) ),
			)
		);

		$this->sync()->run_step();

		self::assertSame( '2026-08-27 10:00:00', $this->deleted_before );
	}

	public function test_processed_count_accumulates_across_steps(): void {
		$this->queue(
			array(
				self::list_response( array( 1, 2 ), 'cursor-2' ),
				self::info_response( array( 1, 2 ) ),
			)
		);

		$this->sync()->run_step();

		$this->queue(
			array(
				self::list_response( array( 3 ), null ),
				self::info_response( array( 3 ) ),
			)
		);

		$state = $this->sync()->run_step();

		self::assertSame( 3, $state->processed );
	}

	public function test_finished_sync_does_not_call_the_api_again(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), null ),
				self::info_response( array( 1 ) ),
			)
		);

		$this->sync()->run_step();

		$this->calls = array();

		$state = $this->sync()->run_step();

		self::assertTrue( $state->finished );
		self::assertSame( array(), $this->calls );
	}

	public function test_start_resets_a_finished_sync(): void {
		$this->options[ CatalogSync::STATE_OPTION ] = array(
			'cursor'     => 'cursor-9',
			'finished'   => true,
			'processed'  => 500,
			'started_at' => '2026-08-01 00:00:00',
		);

		$state = $this->sync()->start();

		self::assertFalse( $state->finished );
		self::assertNull( $state->cursor );
		self::assertSame( 0, $state->processed );
	}

	/**
	 * Ошибка не должна стирать курсор: следующая попытка продолжит с того же
	 * места, а не начнёт каталог заново.
	 */
	public function test_api_failure_preserves_the_cursor(): void {
		$this->queue(
			array(
				self::list_response( array( 1 ), 'cursor-2' ),
				self::info_response( array( 1 ) ),
			)
		);

		$this->sync()->run_step();

		$this->queue( array( self::response( 400, array(), '{"error":{"code":"OE"}}' ) ) );

		try {
			$this->sync()->run_step();
			self::fail( 'Ожидалось ApiException.' );
		} catch ( ApiException ) {
			self::assertSame( 'cursor-2', $this->sync()->state()->cursor );
		}
	}

	public function test_empty_page_does_not_call_info(): void {
		$this->queue( array( self::list_response( array(), null ) ) );

		$this->sync()->run_step();

		self::assertCount( 1, $this->calls );
		self::assertSame( array(), $this->saved );
	}
}
