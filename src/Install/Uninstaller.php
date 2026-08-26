<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

use Spoki\OzonDelivery\Admin\Settings;

final class Uninstaller {

	/**
	 * Все опции, которые плагин создаёт в wp_options.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		Settings::FIELD_CLIENT_ID,
		Settings::FIELD_CLIENT_SECRET,
		Settings::FIELD_SCOPE,
		Settings::FIELD_SHIPMENT_METHOD_ID,
		Settings::FIELD_DRY_RUN,
		Migrations::OPTION_NAME,
	);

	public function run(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
