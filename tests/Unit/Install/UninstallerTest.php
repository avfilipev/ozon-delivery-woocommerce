<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Install;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Install\Uninstaller;
use Spoki\OzonDelivery\Tests\TestCase;

final class UninstallerTest extends TestCase {

	public function test_run_deletes_all_plugin_options(): void {
		$expected = array(
			'ozon_delivery_client_id',
			'ozon_delivery_client_secret',
			'ozon_delivery_scope',
			'ozon_delivery_shipment_method_id',
			'ozon_delivery_dry_run',
			Migrations::OPTION_NAME,
		);

		$deleted = array();

		Functions\expect( 'delete_option' )
			->times( count( $expected ) )
			->andReturnUsing(
				static function ( string $option ) use ( &$deleted ): bool {
					$deleted[] = $option;
					return true;
				}
			);

		( new Uninstaller() )->run();

		self::assertSame( $expected, $deleted );
	}
}
