<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Order\Canceller;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\StatusSync;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class CancellerTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * @var string[]
	 */
	private array $notes = array();

	/**
	 * @var array<string, string>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		$this->meta    = array();
		$this->notes   = array();
		$this->options = array(
			'ozon_delivery_client_id'     => 'id',
			'ozon_delivery_client_secret' => 'secret',
			'ozon_delivery_dry_run'       => 'no',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = '' ) => $this->options[ $name ] ?? $default_value
		);

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function order( string $posting_number = 'POST-1', string $status = 'ON_WAY' ): object {
		if ( '' !== $posting_number ) {
			$this->meta[ Meta::POSTING_NUMBER ] = $posting_number;
		}

		$this->meta[ Meta::POSTING_STATUS ] = $status;

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
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);

		return $order;
	}

	private function canceller(): Canceller {
		$postings = new Postings( ClientFactory::create() );

		return new Canceller( $postings, new StatusSync( $postings, new Logger() ), new Logger() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function info( string $status ): array {
		return self::json(
			array(
				'postings' => array(
					array(
						'posting_number' => 'POST-1',
						'status'         => $status,
					),
				),
			)
		);
	}

	/**
	 * Отмена отвечает 200 без тела, поэтому результат обязательно
	 * перечитывается через posting/info — так велит документация.
	 */
	public function test_cancel_is_verified_through_posting_info(): void {
		$this->queue( array( self::json( array() ), self::info( 'CANCELED' ) ) );

		$result = $this->canceller()->cancel( $this->order() );

		self::assertTrue( $result );
		self::assertSame( 'CANCELED', $this->meta[ Meta::POSTING_STATUS ] );
		self::assertCount( 2, $this->calls );
		self::assertStringContainsString( 'posting/info', $this->calls[1]['url'] );
	}

	/**
	 * Ozon мог принять запрос, но не отменить: доверять «200 без тела» нельзя.
	 */
	public function test_status_that_did_not_change_is_not_a_success(): void {
		$this->queue( array( self::json( array() ), self::info( 'ON_WAY' ) ) );

		$result = $this->canceller()->cancel( $this->order() );

		self::assertFalse( $result );
		self::assertNotSame( array(), $this->notes );
	}

	public function test_error_inside_two_hundred_is_reported(): void {
		$this->queue( array( self::json( array( 'error' => array( 'code' => 'PNF' ) ) ) ) );

		$result = $this->canceller()->cancel( $this->order() );

		self::assertFalse( $result );
		self::assertNotSame( array(), $this->notes );
	}

	/**
	 * Отмена недоступна после вручения — незачем и запрос слать.
	 */
	public function test_delivered_posting_is_not_cancelled(): void {
		$result = $this->canceller()->cancel( $this->order( 'POST-1', 'DELIVERED' ) );

		self::assertFalse( $result );
		self::assertSame( array(), $this->calls );
	}

	public function test_order_without_a_posting_is_skipped(): void {
		$result = $this->canceller()->cancel( $this->order( '' ) );

		self::assertFalse( $result );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * Правило 5 по духу: сбой не должен ронять админку.
	 */
	public function test_api_failure_does_not_throw(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		self::assertFalse( $this->canceller()->cancel( $this->order() ) );
	}

	public function test_dry_run_is_reported_without_cancelling(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::json( array() ) ) );

		$result = $this->canceller()->cancel( $this->order() );

		self::assertFalse( $result );
		self::assertSame( array(), $this->calls );
		self::assertStringContainsString( 'dry-run', mb_strtolower( implode( ' ', $this->notes ) ) );
	}
}
