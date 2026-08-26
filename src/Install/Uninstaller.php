<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

final class Uninstaller {

	/**
	 * Все опции, которые плагин создаёт в wp_options.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		'ozon_delivery_client_id',
		'ozon_delivery_client_secret',
		'ozon_delivery_scope',
		'ozon_delivery_shipment_method_id',
		'ozon_delivery_dry_run',
		Migrations::OPTION_NAME,
	);

	public function run(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
