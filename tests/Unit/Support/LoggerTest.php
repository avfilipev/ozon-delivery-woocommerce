<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;

final class LoggerTest extends TestCase {

	/**
	 * @dataProvider secret_key_provider
	 */
	public function test_mask_replaces_known_secret_keys( string $key ): void {
		$masked = ( new Logger() )->mask( array( $key => 'super-secret-value' ) );

		self::assertSame( '***', $masked[ $key ] );
	}

	public static function secret_key_provider(): array {
		return array(
			'client_secret lowercase' => array( 'client_secret' ),
			'access_token lowercase'  => array( 'access_token' ),
			'cookie lowercase'        => array( 'cookie' ),
			'Client_Secret mixed'     => array( 'Client_Secret' ),
			'ACCESS_TOKEN upper'      => array( 'ACCESS_TOKEN' ),
			'Cookie capitalised'      => array( 'Cookie' ),
		);
	}

	public function test_mask_replaces_secrets_in_nested_arrays(): void {
		$masked = ( new Logger() )->mask(
			array(
				'headers' => array(
					'Cookie'        => 'b2c=abc',
					'Authorization' => 'Bearer xyz',
				),
			)
		);

		self::assertSame( '***', $masked['headers']['Cookie'] );
		self::assertSame( 'Bearer xyz', $masked['headers']['Authorization'] );
	}

	public function test_mask_leaves_unrelated_keys_untouched(): void {
		$masked = ( new Logger() )->mask(
			array(
				'order_id' => 123,
				'method'   => 'order/checkout',
			)
		);

		self::assertSame(
			array(
				'order_id' => 123,
				'method'   => 'order/checkout',
			),
			$masked
		);
	}

	public function test_mask_never_touches_trace_id(): void {
		$masked = ( new Logger() )->mask( array( 'x-o3-trace-id' => 'trace-42' ) );

		self::assertSame( 'trace-42', $masked['x-o3-trace-id'] );
	}

	public function test_log_sends_masked_context_with_source_to_woocommerce_logger(): void {
		$captured_context = null;

		$wc_logger = Mockery::mock();
		$wc_logger->shouldReceive( 'log' )
			->once()
			->with(
				'error',
				'auth failed',
				Mockery::on(
					static function ( array $context ) use ( &$captured_context ): bool {
						$captured_context = $context;
						return true;
					}
				)
			);

		Functions\expect( 'wc_get_logger' )->once()->andReturn( $wc_logger );

		( new Logger() )->log(
			'error',
			'auth failed',
			array(
				'client_secret' => 'shh',
				'x-o3-trace-id' => 'trace-42',
			)
		);

		self::assertSame(
			array(
				'client_secret' => '***',
				'x-o3-trace-id' => 'trace-42',
				'source'        => 'ozon-delivery',
			),
			$captured_context
		);
	}
}
