<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Api\Credentials;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\AuthException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Api\Transport;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class ClientTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();

		// Токен уже в кэше: обращения к OAuth в большинстве тестов не нужны.
		$this->transients[ TokenStore::TRANSIENT ] = 'cached-token';
	}

	private function client( bool $dry_run = false ): Client {
		$transport = new Transport( new CookieJar(), new Logger(), static function ( int $seconds ): void {} );
		$logger    = new Logger();

		return new Client(
			$transport,
			new TokenStore( $transport, new Credentials( 'id', 'secret', 'delivery-api.all' ), $logger ),
			$logger,
			$dry_run
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( int $status, array $payload ): array {
		return self::response( $status, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_request_goes_to_the_delivery_api_base(): void {
		$this->queue( array( self::json( 200, array( 'can_be_delivered' => true ) ) ) );

		$this->client()->post( '/v1/delivery/check-client', array( 'phone_number' => '+70000000000' ) );

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery/check-client', $this->calls[0]['url'] );
	}

	public function test_payload_is_sent_as_json(): void {
		$this->queue( array( self::json( 200, array() ) ) );

		$this->client()->post( '/v1/delivery/check-client', array( 'phone_number' => '+70000000000' ) );

		self::assertSame( '{"phone_number":"+70000000000"}', $this->calls[0]['args']['body'] );
	}

	public function test_authorization_header_carries_the_token(): void {
		$this->queue( array( self::json( 200, array() ) ) );

		$this->client()->post( '/v1/posting/search', array() );

		self::assertSame( 'Bearer cached-token', $this->calls[0]['args']['headers']['Authorization'] );
	}

	public function test_response_json_is_decoded(): void {
		$this->queue( array( self::json( 200, array( 'can_be_delivered' => true ) ) ) );

		$decoded = $this->client()->post( '/v1/delivery/check-client', array() );

		self::assertSame( array( 'can_be_delivered' => true ), $decoded );
	}

	/**
	 * 401 означает, что запрос отвергнут до обработки: токен можно обновить и
	 * повторить, ничего лишнего при этом не создастся.
	 */
	public function test_401_refreshes_the_token_and_retries_once(): void {
		$this->queue(
			array(
				self::json( 401, array( 'error' => array( 'message' => 'token expired' ) ) ),
				self::json(
					200,
					array(
						'access_token' => 'fresh-token',
						'expires_in'   => 3600,
					)
				),
				self::json( 200, array( 'postings' => array() ) ),
			)
		);

		$decoded = $this->client()->post( '/v1/posting/search', array() );

		self::assertSame( array( 'postings' => array() ), $decoded );
		self::assertCount( 3, $this->calls );
		self::assertSame( 'https://xapi.ozon.ru/oauth/token', $this->calls[1]['url'] );
		self::assertSame( 'Bearer fresh-token', $this->calls[2]['args']['headers']['Authorization'] );
	}

	public function test_second_401_is_not_retried_again(): void {
		$this->queue(
			array(
				self::json( 401, array() ),
				self::json(
					200,
					array(
						'access_token' => 'fresh-token',
						'expires_in'   => 3600,
					)
				),
				self::json( 401, array() ),
				self::json(
					200,
					array(
						'access_token' => 'another',
						'expires_in'   => 3600,
					)
				),
			)
		);

		$this->expectException( AuthException::class );

		$this->client()->post( '/v1/posting/search', array() );
	}

	public function test_http_error_reports_ozon_code_and_message(): void {
		$this->queue(
			array(
				self::json(
					400,
					array(
						'error' => array(
							'code'    => 'OE',
							'message' => 'не удалось рассчитать доставку',
						),
					)
				),
			)
		);

		$this->expectException( ApiException::class );
		$this->expectExceptionMessageMatches( '/OE/' );
		$this->expectExceptionMessageMatches( '/рассчитать доставку/u' );

		$this->client()->post( '/v1/order/checkout', array() );
	}

	public function test_trace_id_is_reported_in_the_error(): void {
		// Transport повторяет 5xx, поэтому в очереди все попытки.
		$this->queue( array_fill( 0, 3, self::response( 500, array( 'x-o3-trace-id' => 'trace-77' ), '{}' ) ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessageMatches( '/trace-77/' );

		$this->client()->post( '/v1/posting/info', array() );
	}

	/**
	 * posting/approve и posting/cancel по спецификации отвечают «200 без
	 * тела»: это успех, а не нечитаемый ответ.
	 */
	public function test_empty_body_is_decoded_as_an_empty_result(): void {
		$this->queue( array( self::response( 200, array(), '' ) ) );

		self::assertSame( array(), $this->client()->post( '/v1/posting/approve', array() ) );
	}

	public function test_malformed_json_throws_api_exception(): void {
		$this->queue( array( self::response( 200, array(), 'not json' ) ) );

		$this->expectException( ApiException::class );

		$this->client()->post( '/v1/posting/info', array() );
	}

	/**
	 * Правило 4: order/create только с заголовком Idempotency-Key.
	 */
	public function test_order_create_without_idempotency_key_is_rejected(): void {
		$this->queue( array( self::json( 200, array() ) ) );

		try {
			$this->client()->post( '/v1/order/create', array( 'order_external_id' => 'wc-1' ) );
			self::fail( 'Ожидался отказ до обращения к сети.' );
		} catch ( ApiException ) {
			self::assertSame( array(), $this->calls );
		}
	}

	public function test_order_create_with_idempotency_key_is_sent(): void {
		$this->queue( array( self::json( 200, array( 'order_number' => 'OZN-1' ) ) ) );

		$decoded = $this->client()->post(
			'/v1/order/create',
			array( 'order_external_id' => 'wc-1' ),
			array( 'Idempotency-Key' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11' )
		);

		self::assertSame( array( 'order_number' => 'OZN-1' ), $decoded );
		self::assertSame(
			'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
			$this->calls[0]['args']['headers']['Idempotency-Key']
		);
	}

	/**
	 * Песочницы у Ozon нет: в dry-run запросы на запись не уходят.
	 *
	 * @dataProvider write_method_provider
	 */
	public function test_dry_run_blocks_write_methods( string $path ): void {
		$this->queue( array( self::json( 200, array() ) ) );

		try {
			$this->client( dry_run: true )->post( $path, array(), array( 'Idempotency-Key' => 'uuid' ) );
			self::fail( sprintf( '%s должен быть заблокирован в dry-run.', $path ) );
		} catch ( DryRunException ) {
			self::assertSame( array(), $this->calls );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function write_method_provider(): array {
		return array(
			'order/create'    => array( '/v1/order/create' ),
			'posting/approve' => array( '/v1/posting/approve' ),
			'posting/label'   => array( '/v1/posting/label' ),
			'posting/cancel'  => array( '/v1/posting/cancel' ),
		);
	}

	/**
	 * @dataProvider safe_method_provider
	 */
	public function test_dry_run_allows_safe_methods( string $path ): void {
		$this->queue( array( self::json( 200, array( 'ok' => true ) ) ) );

		$decoded = $this->client( dry_run: true )->post( $path, array() );

		self::assertSame( array( 'ok' => true ), $decoded );
		self::assertCount( 1, $this->calls );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function safe_method_provider(): array {
		return array(
			'delivery/check-client' => array( '/v1/delivery/check-client' ),
			'order/checkout'        => array( '/v1/order/checkout' ),
			'delivery-point/list'   => array( '/v1/delivery-point/list' ),
			'posting/info'          => array( '/v1/posting/info' ),
		);
	}

	public function test_dry_run_logs_the_blocked_request(): void {
		try {
			$this->client( dry_run: true )->post( '/v1/posting/approve', array( 'posting_number' => 'P-1' ) );
		} catch ( DryRunException ) {
			$paths = array_column( $this->logged, 'path' );
			self::assertContains( '/v1/posting/approve', $paths );
		}
	}

	public function test_secrets_never_reach_the_log(): void {
		$this->queue( array( self::json( 200, array() ) ) );

		$this->client()->post( '/v1/posting/search', array() );

		$dump = (string) json_encode( $this->logged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		self::assertStringNotContainsString( 'cached-token', $dump );
	}
}
