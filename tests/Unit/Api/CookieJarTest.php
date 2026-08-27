<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Tests\TestCase;

final class CookieJarTest extends TestCase {

	/**
	 * @param array<string, string> $stored
	 */
	private function jar_with( array $stored ): CookieJar {
		Functions\when( 'get_transient' )->justReturn( array() === $stored ? false : $stored );

		return new CookieJar();
	}

	public function test_header_is_empty_when_nothing_stored(): void {
		self::assertSame( '', $this->jar_with( array() )->header() );
	}

	public function test_remember_parses_set_cookie_and_returns_it_in_header(): void {
		$jar = $this->jar_with( array() );
		Functions\expect( 'set_transient' )->once();

		$jar->remember( 'b2c_cookie=abc123' );

		self::assertSame( 'b2c_cookie=abc123', $jar->header() );
	}

	public function test_remember_strips_cookie_attributes(): void {
		$jar = $this->jar_with( array() );
		Functions\when( 'set_transient' )->justReturn( true );

		$jar->remember( 'b2c_cookie=abc123; path=/; HttpOnly; Secure; expires=Wed, 21 Oct 2026 07:28:00 GMT' );

		self::assertSame( 'b2c_cookie=abc123', $jar->header() );
	}

	public function test_remember_merges_several_set_cookie_headers(): void {
		$jar = $this->jar_with( array() );
		Functions\when( 'set_transient' )->justReturn( true );

		$jar->remember( array( 'first=1; path=/', 'second=2; HttpOnly' ) );

		self::assertSame( 'first=1; second=2', $jar->header() );
	}

	public function test_remember_keeps_previously_stored_cookies(): void {
		$jar = $this->jar_with( array( 'old' => 'kept' ) );
		Functions\when( 'set_transient' )->justReturn( true );

		$jar->remember( 'fresh=new' );

		self::assertSame( 'old=kept; fresh=new', $jar->header() );
	}

	/**
	 * Значение cookie «может измениться без уведомления» — новое вытесняет старое.
	 */
	public function test_remember_overwrites_cookie_with_the_same_name(): void {
		$jar = $this->jar_with( array( 'b2c_cookie' => 'stale' ) );
		Functions\when( 'set_transient' )->justReturn( true );

		$jar->remember( 'b2c_cookie=rotated' );

		self::assertSame( 'b2c_cookie=rotated', $jar->header() );
	}

	public function test_remember_ignores_malformed_set_cookie(): void {
		$jar = $this->jar_with( array() );
		Functions\when( 'set_transient' )->justReturn( true );

		$jar->remember( array( 'no-equals-sign', '   ', '=novalue' ) );

		self::assertSame( '', $jar->header() );
	}

	public function test_remember_persists_to_transient(): void {
		$jar      = $this->jar_with( array() );
		$captured = array();

		Functions\expect( 'set_transient' )
			->once()
			->andReturnUsing(
				static function ( string $key, $value, int $ttl ) use ( &$captured ): bool {
					$captured = array(
						'key'   => $key,
						'value' => $value,
						'ttl'   => $ttl,
					);
					return true;
				}
			);

		$jar->remember( 'b2c_cookie=abc123' );

		self::assertSame(
			array(
				'key'   => CookieJar::TRANSIENT,
				'value' => array( 'b2c_cookie' => 'abc123' ),
				'ttl'   => CookieJar::TTL,
			),
			$captured
		);
	}

	public function test_forget_clears_stored_cookies(): void {
		$jar = $this->jar_with( array( 'b2c_cookie' => 'abc123' ) );

		Functions\expect( 'delete_transient' )->once()->with( CookieJar::TRANSIENT );

		$jar->forget();

		self::assertSame( '', $jar->header() );
	}
}
