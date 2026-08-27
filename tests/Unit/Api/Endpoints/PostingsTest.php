<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class PostingsTest extends TestCase {

	use WpHttpStubs;

	/**
	 * @var array<string, string>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

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

	private function endpoint(): Postings {
		return new Postings( ClientFactory::create() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_approve_sends_the_posting_number(): void {
		$this->queue( array( self::json( array() ) ) );

		$this->endpoint()->approve( 'POST-1' );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/posting/approve', $this->calls[0]['url'] );
		self::assertSame( 'POST-1', $sent['posting_number'] );
	}

	/**
	 * Ozon проверяет баланс кабинета: без денег отгружать нельзя, и это
	 * требует человека, а не повтора.
	 */
	public function test_insufficient_balance_is_reported(): void {
		$this->queue(
			array( self::json( array( 'error' => array( 'code' => 'NEB' ) ) ) )
		);

		$result = $this->endpoint()->approve( 'POST-1' );

		self::assertFalse( $result->succeeded );
		self::assertSame( 'NEB', $result->error_code );
		self::assertTrue( $result->needs_human );
	}

	public function test_unknown_posting_is_reported(): void {
		$this->queue( array( self::json( array( 'error' => array( 'code' => 'PNF' ) ) ) ) );

		$result = $this->endpoint()->approve( 'POST-1' );

		self::assertFalse( $result->succeeded );
		self::assertNotSame( '', $result->message );
	}

	/**
	 * Пустое тело — штатный успешный ответ approve.
	 */
	public function test_empty_body_is_a_success(): void {
		$this->queue( array( self::response( 200, array(), '' ) ) );

		self::assertTrue( $this->endpoint()->approve( 'POST-1' )->succeeded );
	}

	/**
	 * Подтверждение создаёт реальные обязательства и списывает деньги.
	 */
	public function test_dry_run_blocks_approve(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::json( array() ) ) );

		$this->expectException( DryRunException::class );

		$this->endpoint()->approve( 'POST-1' );
	}

	public function test_info_returns_status_and_details(): void {
		$this->queue(
			array(
				self::json(
					array(
						'postings' => array(
							array(
								'posting_number'    => 'POST-1',
								'order_number'      => 'OZN-1',
								'status'            => 'READY_FOR_SHIPPING',
								'status_changed_at' => '2026-08-27T10:00:00Z',
							),
						),
					)
				),
			)
		);

		$postings = $this->endpoint()->info( array( 'POST-1' ) );

		self::assertArrayHasKey( 'POST-1', $postings );
		self::assertSame( 'READY_FOR_SHIPPING', $postings['POST-1']->status->value );
		self::assertSame( 'OZN-1', $postings['POST-1']->order_number );
		self::assertSame( '2026-08-27T10:00:00Z', $postings['POST-1']->status_changed_at );
	}

	public function test_info_without_numbers_makes_no_request(): void {
		$this->queue( array( self::json( array() ) ) );

		self::assertSame( array(), $this->endpoint()->info( array() ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_status_history_is_read(): void {
		$this->queue(
			array(
				self::json(
					array(
						'history' => array(
							array(
								'status'            => 'CREATED',
								'status_changed_at' => '2026-08-26T10:00:00Z',
							),
							array(
								'status'            => 'ON_WAY',
								'status_changed_at' => '2026-08-27T10:00:00Z',
							),
						),
					)
				),
			)
		);

		$history = $this->endpoint()->status_history( 'POST-1' );

		self::assertCount( 2, $history );
		self::assertSame( 'CREATED', $history[0]->status->value );
		self::assertSame( '2026-08-27T10:00:00Z', $history[1]->changed_at );
	}

	/**
	 * Этикетка приходит файлом, а не JSON: разбирать её как JSON нельзя.
	 */
	public function test_label_returns_raw_bytes(): void {
		$this->queue(
			array( self::response( 200, array( 'content-type' => 'application/pdf' ), '%PDF-1.4 fake' ) ),
		);

		$label = $this->endpoint()->label( 'POST-1' );

		self::assertSame( '%PDF-1.4 fake', $label->bytes );
		self::assertSame( 'application/pdf', $label->content_type );
		self::assertStringContainsString( 'POST-1', $label->filename );
	}

	public function test_label_is_blocked_in_dry_run(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::response( 200, array(), '%PDF' ) ) );

		$this->expectException( DryRunException::class );

		$this->endpoint()->label( 'POST-1' );
	}

	public function test_empty_label_is_an_error(): void {
		$this->queue( array( self::response( 200, array(), '' ) ) );

		$this->expectException( ApiException::class );

		$this->endpoint()->label( 'POST-1' );
	}

	public function test_cancel_sends_the_posting_number(): void {
		$this->queue( array( self::json( array() ) ) );

		$result = $this->endpoint()->cancel( 'POST-1' );

		self::assertTrue( $result->succeeded );
		self::assertSame( 'https://api-delivery.ozon.ru/v1/posting/cancel', $this->calls[0]['url'] );
	}

	public function test_cancel_is_blocked_in_dry_run(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::json( array() ) ) );

		$this->expectException( DryRunException::class );

		$this->endpoint()->cancel( 'POST-1' );
	}
}
