<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class ClientFactoryTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();
	}

	/**
	 * @param array<string, string> $options
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => $options[ $name ] ?? $default_value
		);
	}

	public function test_creates_a_client(): void {
		$this->stub_options( array() );

		self::assertInstanceOf( Client::class, ClientFactory::create() );
	}

	/**
	 * Песочницы у Ozon нет: пока настройка не сохранена, боевые запросы на
	 * запись уходить не должны.
	 */
	public function test_dry_run_is_on_when_the_option_was_never_saved(): void {
		$this->stub_options( array() );

		self::assertTrue( ClientFactory::is_dry_run() );
	}

	public function test_dry_run_is_on_when_enabled_in_settings(): void {
		$this->stub_options( array( Settings::FIELD_DRY_RUN => 'yes' ) );

		self::assertTrue( ClientFactory::is_dry_run() );
	}

	public function test_dry_run_is_off_only_when_explicitly_disabled(): void {
		$this->stub_options( array( Settings::FIELD_DRY_RUN => 'no' ) );

		self::assertFalse( ClientFactory::is_dry_run() );
	}

	public function test_created_client_respects_dry_run(): void {
		$this->stub_options(
			array(
				Settings::FIELD_CLIENT_ID     => 'id',
				Settings::FIELD_CLIENT_SECRET => 'secret',
				Settings::FIELD_DRY_RUN       => 'yes',
			)
		);

		$this->queue( array( self::ok() ) );

		$this->expectException( \Spoki\OzonDelivery\Api\Exception\DryRunException::class );

		ClientFactory::create()->post( '/v1/posting/approve', array( 'posting_number' => 'P-1' ) );
	}
}
