<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Api\Credentials;
use Spoki\OzonDelivery\Api\Exception\AuthException;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Api\Transport;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class TokenStoreTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
	}

	private function store(
		string $scope = 'delivery-api.all',
		string $client_id = 'client-1',
		string $client_secret = 'secret-1'
	): TokenStore {
		return new TokenStore(
			new Transport( new CookieJar(), new Logger(), static function ( int $seconds ): void {} ),
			new Credentials( $client_id, $client_secret, $scope ),
			new Logger()
		);
	}

	/**
	 * Ответ токена по RFC 6749: docs/API.md называет grant_type
	 * client_credentials, но саму схему ответа не описывает.
	 */
	private static function token_response( string $token = 'access-token-1', ?int $expires_in = 3600 ): array {
		$payload = array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
		);

		if ( null !== $expires_in ) {
			$payload['expires_in'] = $expires_in;
		}

		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_token_is_fetched_when_cache_is_empty(): void {
		$this->queue( array( self::token_response( 'fresh-token' ) ) );

		self::assertSame( 'fresh-token', $this->store()->token() );
	}

	public function test_cached_token_is_returned_without_http_call(): void {
		Functions\when( 'get_transient' )->justReturn( 'cached-token' );
		$this->queue( array( self::token_response( 'fresh-token' ) ) );

		self::assertSame( 'cached-token', $this->store()->token() );
		self::assertSame( array(), $this->calls );
	}

	public function test_token_request_goes_to_the_oauth_endpoint(): void {
		$this->queue( array( self::token_response() ) );

		$this->store()->token();

		self::assertSame( 'https://xapi.ozon.ru/oauth/token', $this->calls[0]['url'] );
	}

	public function test_token_request_sends_client_credentials_grant(): void {
		$this->queue( array( self::token_response() ) );

		$this->store( 'delivery-api.order', 'my-id', 'my-secret' )->token();

		parse_str( $this->calls[0]['args']['body'], $sent );

		self::assertSame(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => 'my-id',
				'client_secret' => 'my-secret',
				'scope'         => 'delivery-api.order',
			),
			$sent
		);
	}

	public function test_token_request_is_form_encoded(): void {
		$this->queue( array( self::token_response() ) );

		$this->store()->token();

		self::assertSame(
			'application/x-www-form-urlencoded',
			$this->calls[0]['args']['headers']['Content-Type']
		);
	}

	public function test_scope_is_omitted_when_not_configured(): void {
		$this->queue( array( self::token_response() ) );

		$this->store( '' )->token();

		parse_str( $this->calls[0]['args']['body'], $sent );

		self::assertArrayNotHasKey( 'scope', $sent );
	}

	public function test_token_is_cached_shorter_than_its_lifetime(): void {
		$captured = array();

		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl ) use ( &$captured ): bool {
				$captured = array(
					'key'   => $key,
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);

		$this->queue( array( self::token_response( 'tok', 3600 ) ) );

		$this->store()->token();

		self::assertSame( TokenStore::TRANSIENT, $captured['key'] );
		self::assertSame( 'tok', $captured['value'] );
		self::assertSame( 3600 - TokenStore::EXPIRY_MARGIN, $captured['ttl'] );
	}

	public function test_missing_expires_in_falls_back_to_short_ttl(): void {
		$ttl = null;

		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $seconds ) use ( &$ttl ): bool {
				$ttl = $seconds;
				return true;
			}
		);

		$this->queue( array( self::token_response( 'tok', null ) ) );

		$this->store()->token();

		self::assertSame( TokenStore::FALLBACK_TTL, $ttl );
	}

	/**
	 * Короткоживущий токен не должен давать отрицательный или нулевой TTL.
	 */
	public function test_very_short_lifetime_still_yields_positive_ttl(): void {
		$ttl = null;

		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $seconds ) use ( &$ttl ): bool {
				$ttl = $seconds;
				return true;
			}
		);

		$this->queue( array( self::token_response( 'tok', 10 ) ) );

		$this->store()->token();

		self::assertIsInt( $ttl );
		self::assertGreaterThan( 0, $ttl );
	}

	public function test_missing_credentials_throw_auth_exception_without_http_call(): void {
		$this->queue( array( self::token_response() ) );

		try {
			$this->store( 'delivery-api.all', '', '' )->token();
			self::fail( 'Ожидалось AuthException до обращения к сети.' );
		} catch ( AuthException ) {
			self::assertSame( array(), $this->calls );
		}
	}

	public function test_error_status_throws_auth_exception(): void {
		$this->queue( array( self::response( 401, array(), '{"error":"invalid_client"}' ) ) );

		$this->expectException( AuthException::class );

		$this->store()->token();
	}

	public function test_malformed_json_throws_auth_exception(): void {
		$this->queue( array( self::response( 200, array(), 'not json at all' ) ) );

		$this->expectException( AuthException::class );

		$this->store()->token();
	}

	public function test_response_without_access_token_throws_auth_exception(): void {
		$this->queue( array( self::response( 200, array(), '{"token_type":"Bearer"}' ) ) );

		$this->expectException( AuthException::class );

		$this->store()->token();
	}

	public function test_forget_clears_the_cached_token(): void {
		$deleted = null;

		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted = $key;
				return true;
			}
		);

		$this->store()->forget();

		self::assertSame( TokenStore::TRANSIENT, $deleted );
	}

	public function test_token_is_refetched_after_forget(): void {
		$this->queue(
			array(
				self::token_response( 'first' ),
				self::token_response( 'second' ),
			)
		);

		$store = $this->store();

		self::assertSame( 'first', $store->token() );

		$store->forget();

		self::assertSame( 'second', $store->token() );
	}

	/**
	 * Ни сам токен, ни client_secret не должны попасть в журнал.
	 */
	public function test_secrets_never_reach_the_log(): void {
		$this->queue( array( self::token_response( 'super-secret-token' ) ) );

		$this->store( 'delivery-api.all', 'client-1', 'super-secret-client' )->token();

		$dump = (string) json_encode( $this->logged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		self::assertStringNotContainsString( 'super-secret-token', $dump );
		self::assertStringNotContainsString( 'super-secret-client', $dump );
	}
}
