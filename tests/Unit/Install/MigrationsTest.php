<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Install;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Tests\TestCase;

final class MigrationsTest extends TestCase {

	public function test_run_stamps_current_version_on_fresh_install(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Migrations::OPTION_NAME, false )
			->andReturn( false );

		Functions\expect( 'update_option' )
			->once()
			->with( Migrations::OPTION_NAME, Migrations::CURRENT_VERSION );

		( new Migrations() )->run();

		$this->expectNotToPerformAssertions();
	}

	public function test_run_updates_stale_version_to_current(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Migrations::OPTION_NAME, false )
			->andReturn( '0.0.1' );

		Functions\expect( 'update_option' )
			->once()
			->with( Migrations::OPTION_NAME, Migrations::CURRENT_VERSION );

		( new Migrations() )->run();

		$this->expectNotToPerformAssertions();
	}

	public function test_run_does_nothing_when_already_current(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Migrations::OPTION_NAME, false )
			->andReturn( Migrations::CURRENT_VERSION );

		Functions\expect( 'update_option' )->never();

		( new Migrations() )->run();

		$this->expectNotToPerformAssertions();
	}
}
