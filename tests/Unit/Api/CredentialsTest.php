<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\Credentials;
use Spoki\OzonDelivery\Tests\TestCase;

final class CredentialsTest extends TestCase {

	/**
	 * @param array<string, string> $options
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => $options[ $name ] ?? $default_value
		);
	}

	public function test_from_options_reads_plugin_settings(): void {
		$this->stub_options(
			array(
				Settings::FIELD_CLIENT_ID     => 'id-from-settings',
				Settings::FIELD_CLIENT_SECRET => 'secret-from-settings',
				Settings::FIELD_SCOPE         => 'delivery-api.all',
			)
		);

		$credentials = Credentials::from_options();

		self::assertSame( 'id-from-settings', $credentials->client_id );
		self::assertSame( 'secret-from-settings', $credentials->client_secret );
		self::assertSame( 'delivery-api.all', $credentials->scope );
	}

	public function test_from_options_falls_back_to_empty_strings(): void {
		$this->stub_options( array() );

		$credentials = Credentials::from_options();

		self::assertSame( '', $credentials->client_id );
		self::assertSame( '', $credentials->client_secret );
		self::assertSame( '', $credentials->scope );
	}

	public function test_credentials_are_complete_when_id_and_secret_are_set(): void {
		self::assertTrue( ( new Credentials( 'id', 'secret' ) )->are_complete() );
	}

	/**
	 * @dataProvider incomplete_credentials_provider
	 */
	public function test_credentials_are_incomplete_without_id_or_secret( string $id, string $secret ): void {
		self::assertFalse( ( new Credentials( $id, $secret ) )->are_complete() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function incomplete_credentials_provider(): array {
		return array(
			'нет обоих'  => array( '', '' ),
			'нет id'     => array( '', 'secret' ),
			'нет secret' => array( 'id', '' ),
		);
	}

	/**
	 * Scope необязателен: у частного приложения он может быть не задан.
	 */
	public function test_scope_is_optional(): void {
		self::assertSame( '', ( new Credentials( 'id', 'secret' ) )->scope );
	}
}
