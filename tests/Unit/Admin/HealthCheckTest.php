<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Admin\HealthCheck;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class HealthCheckTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();
		$this->stub_options(
			array(
				Settings::FIELD_CLIENT_ID     => 'id',
				Settings::FIELD_CLIENT_SECRET => 'secret',
			)
		);
	}

	/**
	 * @param array<string, string> $options
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => $options[ $name ] ?? $default_value
		);
	}

	private function health_check(): HealthCheck {
		return new HealthCheck( ClientFactory::create() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( int $status, array $payload ): array {
		return self::response( $status, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_probe_uses_a_method_that_creates_nothing(): void {
		$this->transients['ozon_delivery_access_token'] = 'tok';
		$this->queue( array( self::json( 200, array( 'delivery_points' => array() ) ) ) );

		$this->health_check()->run();

		self::assertSame( 'https://api-delivery.ozon.ru/v1/delivery-point/list', $this->calls[0]['url'] );
	}

	public function test_successful_probe_reports_ok(): void {
		$this->transients['ozon_delivery_access_token'] = 'tok';
		$this->queue(
			array(
				self::json(
					200,
					array(
						'delivery_points' => array( array( 'delivery_point_id' => 1 ) ),
					)
				),
			)
		);

		$result = $this->health_check()->run();

		self::assertTrue( $result->ok );
		self::assertNotSame( '', $result->message );
	}

	public function test_missing_credentials_are_reported_without_a_network_call(): void {
		$this->stub_options( array() );

		$result = $this->health_check()->run();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'client_id', $result->message );
		self::assertSame( array(), $this->calls );
	}

	public function test_rejected_keys_are_reported(): void {
		$this->queue( array( self::json( 401, array( 'error' => 'invalid_client' ) ) ) );

		$result = $this->health_check()->run();

		self::assertFalse( $result->ok );
		self::assertNotSame( '', $result->message );
	}

	public function test_api_error_is_reported_with_trace_id(): void {
		$this->transients['ozon_delivery_access_token'] = 'tok';
		$this->queue(
			array(
				self::response( 403, array( 'x-o3-trace-id' => 'trace-55' ), '{"error":{"code":"PE"}}' ),
			)
		);

		$result = $this->health_check()->run();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'trace-55', $result->message );
	}

	public function test_network_failure_is_reported(): void {
		$this->transients['ozon_delivery_access_token'] = 'tok';
		$this->queue( array_fill( 0, 5, new \WP_Error( 'http_request_failed', 'cURL error 28' ) ) );

		$result = $this->health_check()->run();

		self::assertFalse( $result->ok );
		self::assertNotSame( '', $result->message );
	}

	/**
	 * Проверка подключения обязана работать и при включённом dry-run:
	 * delivery-point/list ничего не создаёт.
	 */
	public function test_probe_works_in_dry_run_mode(): void {
		$this->stub_options(
			array(
				Settings::FIELD_CLIENT_ID     => 'id',
				Settings::FIELD_CLIENT_SECRET => 'secret',
				Settings::FIELD_DRY_RUN       => 'yes',
			)
		);
		$this->transients['ozon_delivery_access_token'] = 'tok';
		$this->queue( array( self::json( 200, array( 'delivery_points' => array() ) ) ) );

		self::assertTrue( $this->health_check()->run()->ok );
	}

	public function test_result_never_leaks_secrets(): void {
		$this->transients['ozon_delivery_access_token'] = 'super-secret-token';
		$this->queue( array( self::json( 401, array() ) ) );

		$result = $this->health_check()->run();

		self::assertStringNotContainsString( 'super-secret-token', $result->message );
	}
}
