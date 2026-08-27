<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests;

use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * Стабы HTTP-функций WordPress и журнала WooCommerce.
 *
 * Transport и TokenStore тестируются на настоящих объектах (не на моках),
 * поэтому подменяется только слой WordPress под ними.
 */
trait WpHttpStubs {

	/**
	 * Аргументы каждого вызова wp_remote_post по порядку.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	protected array $calls = array();

	/**
	 * Контексты, попавшие в журнал WooCommerce.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $logged = array();

	protected function stub_wp_http(): void {
		$this->calls  = array();
		$this->logged = array();

		$wc_logger = Mockery::mock();
		$wc_logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->logged[] = $context;
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( $wc_logger );

		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ) => json_encode( $data ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => $response['response']['code'] ?? 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ): string => $response['body'] ?? ''
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $response, string $name ) => $response['headers'][ strtolower( $name ) ] ?? ''
		);
	}

	/**
	 * Ставит в очередь ответы wp_remote_post и записывает аргументы вызовов.
	 *
	 * @param array<int, mixed> $responses
	 */
	protected function queue( array $responses ): void {
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ) use ( &$responses ) {
				$this->calls[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array_shift( $responses ) ?? self::ok();
			}
		);
	}

	/**
	 * @param array<string, string|string[]> $headers
	 * @return array<string, mixed>
	 */
	protected static function response( int $code, array $headers = array(), string $body = '' ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers + array( 'x-o3-trace-id' => 'trace-default' ),
			'body'     => $body,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected static function ok( string $body = '{"ok":true}' ): array {
		return self::response( 200, array(), $body );
	}
}
