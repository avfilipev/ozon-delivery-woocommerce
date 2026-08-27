<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Api\Exception\RateLimitException;
use Spoki\OzonDelivery\Api\Exception\TransportException;
use Spoki\OzonDelivery\Api\Transport;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;
use WP_Error;

final class TransportTest extends TestCase {

	use WpHttpStubs;

	private const URL = 'https://api-delivery.ozon.ru/v1/posting/info';

	/**
	 * Задержки, которые Transport попросил выждать, вместо реального сна.
	 *
	 * @var int[]
	 */
	private array $slept = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->slept = array();
	}

	/**
	 * Настоящие CookieJar и Logger поверх застабленных транзиентов и
	 * wc_get_logger: так тест проверяет и связку с ними, а не только моки.
	 *
	 * @param array<string, string> $stored_cookies
	 */
	private function transport( array $stored_cookies = array() ): Transport {
		if ( array() !== $stored_cookies ) {
			Functions\when( 'get_transient' )->justReturn( $stored_cookies );
		}

		return new Transport(
			new CookieJar(),
			new Logger(),
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			}
		);
	}

	public function test_successful_post_returns_status_body_and_trace_id(): void {
		$this->queue(
			array(
				self::response( 200, array( 'x-o3-trace-id' => 'trace-42' ), '{"ok":true}' ),
			)
		);

		$response = $this->transport()->post( self::URL, '{"posting_numbers":["1"]}' );

		self::assertSame( 200, $response->status );
		self::assertSame( '{"ok":true}', $response->body );
		self::assertSame( 'trace-42', $response->trace_id );
	}

	public function test_request_is_sent_as_post_with_body_and_json_content_type(): void {
		$this->queue( array( self::ok() ) );

		$this->transport()->post( self::URL, '{"a":1}' );

		self::assertCount( 1, $this->calls );
		self::assertSame( self::URL, $this->calls[0]['url'] );
		self::assertSame( '{"a":1}', $this->calls[0]['args']['body'] );
		self::assertSame( 'POST', $this->calls[0]['args']['method'] );
		self::assertSame( 'application/json', $this->calls[0]['args']['headers']['Content-Type'] );
	}

	/**
	 * wp_remote_post на 302 через cURL-транспорт превращает POST в GET и теряет
	 * тело. Редиректы обязаны разбираться вручную, поэтому redirection = 0.
	 */
	public function test_redirects_are_disabled_in_wp_http_args(): void {
		$this->queue( array( self::ok() ) );

		$this->transport()->post( self::URL, '{}' );

		self::assertSame( 0, $this->calls[0]['args']['redirection'] );
	}

	/**
	 * Главный сценарий testcookie из docs/API.md: 307 с Location и Set-Cookie →
	 * повтор POST с тем же телом и приложенным cookie → 200.
	 *
	 * @dataProvider redirect_status_provider
	 */
	public function test_redirect_repeats_post_with_same_body_and_cookie( int $status ): void {
		$this->queue(
			array(
				self::response(
					$status,
					array(
						'location'   => 'https://api-delivery.ozon.ru/v1/posting/info?check=1',
						'set-cookie' => 'b2c_cookie=fresh; path=/',
					)
				),
				self::ok( '{"postings":[]}' ),
			)
		);

		$response = $this->transport()->post( self::URL, '{"posting_numbers":["1"]}' );

		self::assertSame( 200, $response->status );
		self::assertSame( '{"postings":[]}', $response->body );

		self::assertCount( 2, $this->calls );

		// Повтор идёт по адресу из Location, методом POST, с тем же телом.
		self::assertSame( 'https://api-delivery.ozon.ru/v1/posting/info?check=1', $this->calls[1]['url'] );
		self::assertSame( 'POST', $this->calls[1]['args']['method'] );
		self::assertSame( '{"posting_numbers":["1"]}', $this->calls[1]['args']['body'] );
		self::assertSame( 'b2c_cookie=fresh', $this->calls[1]['args']['headers']['Cookie'] );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function redirect_status_provider(): array {
		return array(
			'307 Temporary Redirect' => array( 307 ),
			'302 Found'              => array( 302 ),
		);
	}

	public function test_stored_cookie_is_sent_on_the_first_request(): void {
		$this->queue( array( self::ok() ) );

		$this->transport( array( 'b2c_cookie' => 'stored' ) )->post( self::URL, '{}' );

		self::assertSame( 'b2c_cookie=stored', $this->calls[0]['args']['headers']['Cookie'] );
	}

	public function test_cookie_header_is_omitted_when_jar_is_empty(): void {
		$this->queue( array( self::ok() ) );

		$this->transport()->post( self::URL, '{}' );

		self::assertArrayNotHasKey( 'Cookie', $this->calls[0]['args']['headers'] );
	}

	/**
	 * Cookie «может измениться без уведомления» — редирект в середине сессии
	 * обрабатывается так же, как на первом запросе.
	 */
	public function test_cookie_rotated_mid_session_is_stored(): void {
		$this->queue(
			array(
				self::response(
					307,
					array(
						'location'   => self::URL,
						'set-cookie' => 'b2c_cookie=rotated; path=/',
					)
				),
				self::ok(),
			)
		);

		$this->transport( array( 'b2c_cookie' => 'stale' ) )->post( self::URL, '{}' );

		self::assertSame( 'b2c_cookie=stale', $this->calls[0]['args']['headers']['Cookie'] );
		self::assertSame( 'b2c_cookie=rotated', $this->calls[1]['args']['headers']['Cookie'] );
	}

	public function test_several_set_cookie_headers_are_all_remembered(): void {
		$this->queue(
			array(
				self::response(
					307,
					array(
						'location'   => self::URL,
						'set-cookie' => array( 'a=1', 'b=2' ),
					)
				),
				self::ok(),
			)
		);

		$this->transport()->post( self::URL, '{}' );

		self::assertSame( 'a=1; b=2', $this->calls[1]['args']['headers']['Cookie'] );
	}

	public function test_redirect_loop_is_aborted(): void {
		$redirect = self::response( 307, array( 'location' => self::URL ) );

		$this->queue( array_fill( 0, 10, $redirect ) );

		$this->expectException( TransportException::class );
		$this->expectExceptionMessageMatches( '/редирект/iu' );

		$this->transport()->post( self::URL, '{}' );
	}

	public function test_redirect_without_location_is_not_followed(): void {
		$this->queue( array( self::response( 307, array( 'set-cookie' => 'a=1' ) ) ) );

		$this->expectException( TransportException::class );

		$this->transport()->post( self::URL, '{}' );
	}

	public function test_429_is_retried_and_then_succeeds(): void {
		$this->queue(
			array(
				self::response( 429 ),
				self::ok( '{"retried":true}' ),
			)
		);

		$response = $this->transport()->post( self::URL, '{}' );

		self::assertSame( 200, $response->status );
		self::assertSame( '{"retried":true}', $response->body );
		self::assertCount( 2, $this->calls );
	}

	public function test_retries_use_exponential_backoff(): void {
		$this->queue(
			array(
				self::response( 429 ),
				self::response( 429 ),
				self::ok(),
			)
		);

		$this->transport()->post( self::URL, '{}' );

		self::assertSame( array( 1, 2 ), $this->slept );
	}

	public function test_retry_after_header_wins_over_backoff(): void {
		$this->queue(
			array(
				self::response( 429, array( 'retry-after' => '7' ) ),
				self::ok(),
			)
		);

		$this->transport()->post( self::URL, '{}' );

		self::assertSame( array( 7 ), $this->slept );
	}

	public function test_429_exhausts_retries_and_throws_rate_limit_exception(): void {
		$this->queue( array_fill( 0, 5, self::response( 429 ) ) );

		$this->expectException( RateLimitException::class );

		$this->transport()->post( self::URL, '{}' );
	}

	public function test_server_error_is_retried(): void {
		$this->queue(
			array(
				self::response( 503 ),
				self::ok(),
			)
		);

		$response = $this->transport()->post( self::URL, '{}' );

		self::assertSame( 200, $response->status );
		self::assertCount( 2, $this->calls );
	}

	/**
	 * У 5xx в спеке описано тело с error.code и error.message, поэтому после
	 * исчерпания попыток ответ отдаётся вызывающему коду — тот покажет причину.
	 * Исключение бросается только там, где ответа нет вовсе.
	 */
	public function test_server_error_is_returned_after_retries_are_exhausted(): void {
		$this->queue( array_fill( 0, 5, self::response( 503, array(), '{"error":{"code":"OE"}}' ) ) );

		$response = $this->transport()->post( self::URL, '{}' );

		self::assertSame( 503, $response->status );
		self::assertSame( '{"error":{"code":"OE"}}', $response->body );
		self::assertCount( 3, $this->calls );
	}

	public function test_client_error_is_returned_without_retry(): void {
		$this->queue( array( self::response( 400, array(), '{"error":{"code":"OE"}}' ) ) );

		$response = $this->transport()->post( self::URL, '{}' );

		self::assertSame( 400, $response->status );
		self::assertSame( '{"error":{"code":"OE"}}', $response->body );
		self::assertCount( 1, $this->calls );
	}

	public function test_network_error_is_retried_then_throws_transport_exception(): void {
		$this->queue( array_fill( 0, 5, new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) ) );

		$this->expectException( TransportException::class );
		$this->expectExceptionMessageMatches( '/timeout/i' );

		$this->transport()->post( self::URL, '{}' );
	}

	public function test_network_error_is_retried_and_can_succeed(): void {
		$this->queue(
			array(
				new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ),
				self::ok(),
			)
		);

		$response = $this->transport()->post( self::URL, '{}' );

		self::assertSame( 200, $response->status );
		self::assertCount( 2, $this->calls );
	}

	/**
	 * order/create неидемпотентен: повтор после таймаута может создать второе
	 * отправление, поэтому вызывающий код обязан уметь отключить ретраи.
	 */
	public function test_non_retryable_call_is_not_retried_on_429(): void {
		$this->queue(
			array(
				self::response( 429 ),
				self::ok(),
			)
		);

		try {
			$this->transport()->post( self::URL, '{}', array(), retry_on_failure: false );
			self::fail( 'Ожидалось RateLimitException без повтора запроса.' );
		} catch ( RateLimitException ) {
			self::assertCount( 1, $this->calls );
			self::assertSame( array(), $this->slept );
		}
	}

	public function test_non_retryable_call_is_not_retried_on_network_error(): void {
		$this->queue(
			array(
				new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ),
				self::ok(),
			)
		);

		try {
			$this->transport()->post( self::URL, '{}', array(), retry_on_failure: false );
			self::fail( 'Ожидалось TransportException без повтора запроса.' );
		} catch ( TransportException ) {
			self::assertCount( 1, $this->calls );
		}
	}

	/**
	 * Редирект testcookie — не сбой, а штатный механизм: он обрабатывается
	 * даже там, где ретраи запрещены.
	 */
	public function test_non_retryable_call_still_follows_redirects(): void {
		$this->queue(
			array(
				self::response(
					307,
					array(
						'location'   => self::URL,
						'set-cookie' => 'a=1',
					)
				),
				self::ok(),
			)
		);

		$response = $this->transport()->post( self::URL, '{}', array(), retry_on_failure: false );

		self::assertSame( 200, $response->status );
		self::assertCount( 2, $this->calls );
	}

	public function test_caller_headers_are_sent(): void {
		$this->queue( array( self::ok() ) );

		$this->transport()->post( self::URL, '{}', array( 'Idempotency-Key' => 'uuid-1' ) );

		self::assertSame( 'uuid-1', $this->calls[0]['args']['headers']['Idempotency-Key'] );
	}

	/**
	 * x-o3-trace-id логируется всегда — его спрашивает поддержка Ozon.
	 */
	public function test_trace_id_is_always_logged(): void {
		$this->queue( array( self::response( 200, array( 'x-o3-trace-id' => 'trace-99' ) ) ) );

		$this->transport()->post( self::URL, '{}' );

		self::assertContains( 'trace-99', array_column( $this->logged, 'x-o3-trace-id' ) );
	}

	/**
	 * Через Transport проходят Authorization и Cookie — в журнале их быть
	 * не должно даже фрагментами.
	 */
	public function test_secrets_never_reach_the_log(): void {
		$this->queue(
			array(
				self::response(
					307,
					array(
						'location'   => self::URL,
						'set-cookie' => 'b2c_cookie=supersecret',
					)
				),
				self::ok(),
			)
		);

		$this->transport()->post(
			self::URL,
			'{}',
			array( 'Authorization' => 'Bearer secret-token-value' )
		);

		$dump = wp_json_encode( $this->logged );

		self::assertIsString( $dump );
		self::assertStringNotContainsString( 'secret-token-value', $dump );
		self::assertStringNotContainsString( 'supersecret', $dump );
	}
}
