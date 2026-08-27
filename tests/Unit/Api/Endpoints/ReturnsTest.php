<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api\Endpoints;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Returns;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class ReturnsTest extends TestCase {

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

	private function endpoint(): Returns {
		return new Returns( ClientFactory::create() );
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
	private static function return_item( string $number = 'RET-1', string $status = 'MOVING' ): array {
		return array(
			'return_number'          => $number,
			'return_external_id'     => 'wc-123',
			'status'                 => $status,
			'status_changed_at'      => '2026-08-27T10:00:00Z',
			'created_at'             => '2026-08-26T10:00:00Z',
			'barcode'                => 'BC-1',
			'return_type'            => 'CANCELLATION',
			'description'            => 'Заказ №123',
			'shipment_method_id'     => 777,
			'current_placement_name' => 'ПВЗ на Тверской',
			'cancellation_reason'    => 'Покупатель отказался',
		);
	}

	public function test_search_uses_cursor_pagination(): void {
		$this->queue( array( self::json( array( 'returns' => array() ) ) ) );

		$this->endpoint()->search( 'cursor-2', 100 );

		$sent = json_decode( $this->calls[0]['args']['body'], true );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/return/search', $this->calls[0]['url'] );
		self::assertSame( 'cursor-2', $sent['pagination']['cursor'] );
		self::assertSame( 100, $sent['pagination']['limit'] );
	}

	public function test_search_returns_parsed_returns_and_cursor(): void {
		$this->queue(
			array(
				self::json(
					array(
						'returns'     => array( self::return_item( 'RET-1' ), self::return_item( 'RET-2' ) ),
						'next_cursor' => 'cursor-3',
					)
				),
			)
		);

		$page = $this->endpoint()->search();

		self::assertCount( 2, $page->returns );
		self::assertSame( 'RET-1', $page->returns[0]->return_number );
		self::assertSame( 'cursor-3', $page->next_cursor );
		self::assertFalse( $page->is_last() );
	}

	public function test_page_without_cursor_is_the_last(): void {
		$this->queue( array( self::json( array( 'returns' => array() ) ) ) );

		self::assertTrue( $this->endpoint()->search()->is_last() );
	}

	public function test_return_details_are_parsed(): void {
		$this->queue( array( self::json( array( 'returns' => array( self::return_item( 'RET-1', 'RECEIVED' ) ) ) ) ) );

		$returns = $this->endpoint()->info( array( 'RET-1' ) );

		self::assertArrayHasKey( 'RET-1', $returns );
		self::assertSame( 'RECEIVED', $returns['RET-1']->status->value );
		self::assertSame( 'wc-123', $returns['RET-1']->return_external_id );
		self::assertSame( 'BC-1', $returns['RET-1']->barcode );
		self::assertSame( 'ПВЗ на Тверской', $returns['RET-1']->current_placement_name );
		self::assertSame( 'Покупатель отказался', $returns['RET-1']->cancellation_reason );
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
						'status_history' => array(
							array(
								'return_status' => 'MOVING',
								'changed_at'    => '2026-08-26T10:00:00Z',
							),
							array(
								'return_status' => 'RECEIVED',
								'changed_at'    => '2026-08-27T10:00:00Z',
							),
						),
					)
				),
			)
		);

		$history = $this->endpoint()->status_history( 'RET-1' );

		self::assertCount( 2, $history );
		self::assertSame( 'MOVING', $history[0]->status->value );
		self::assertSame( '2026-08-27T10:00:00Z', $history[1]->changed_at );
	}

	/**
	 * Штрихкод приходит PDF-файлом и запроса без тела: разбирать как JSON нельзя.
	 */
	public function test_barcode_is_downloaded_as_a_file(): void {
		$this->queue( array( self::response( 200, array( 'content-type' => 'application/pdf' ), '%PDF-1.4 barcode' ) ) );

		$barcode = $this->endpoint()->download_barcode();

		self::assertSame( '%PDF-1.4 barcode', $barcode->bytes );
		self::assertSame( 'application/pdf', $barcode->content_type );
		self::assertStringContainsString( '.pdf', $barcode->filename );
	}

	public function test_empty_barcode_is_an_error(): void {
		$this->queue( array( self::response( 200, array(), '' ) ) );

		$this->expectException( ApiException::class );

		$this->endpoint()->download_barcode();
	}

	public function test_reset_barcode_returns_the_new_value(): void {
		$this->queue(
			array(
				self::json(
					array(
						'barcode_content' => array(
							'barcode'    => 'BC-NEW',
							'expires_at' => '2026-09-01T00:00:00Z',
						),
					)
				),
			)
		);

		$barcode = $this->endpoint()->reset_barcode();

		self::assertSame( 'BC-NEW', $barcode['barcode'] );
		self::assertSame( '2026-09-01T00:00:00Z', $barcode['expires_at'] );
	}

	/**
	 * Сброс обесценивает уже напечатанный штрихкод — в dry-run это делать
	 * нельзя.
	 */
	public function test_reset_barcode_is_blocked_in_dry_run(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::json( array() ) ) );

		$this->expectException( DryRunException::class );

		$this->endpoint()->reset_barcode();
	}

	/**
	 * Скачивание штрихкода ничего не меняет и должно работать всегда.
	 */
	public function test_download_barcode_works_in_dry_run(): void {
		$this->options['ozon_delivery_dry_run'] = 'yes';

		$this->queue( array( self::response( 200, array(), '%PDF' ) ) );

		self::assertSame( '%PDF', $this->endpoint()->download_barcode()->bytes );
	}
}
