<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Сборка клиента из настроек плагина.
 */
final class ClientFactory {

	public static function create(): Client {
		$logger    = new Logger();
		$transport = new Transport( new CookieJar(), $logger );

		return new Client(
			$transport,
			new TokenStore( $transport, Credentials::from_options(), $logger ),
			$logger,
			self::is_dry_run()
		);
	}

	/**
	 * Dry-run включён, пока его явно не выключили.
	 *
	 * Песочницы у Ozon нет, боевой контур один, поэтому умолчание безопасное:
	 * на свежей установке запросы на запись не уходят.
	 */
	public static function is_dry_run(): bool {
		return 'no' !== get_option( Settings::FIELD_DRY_RUN, 'yes' );
	}
}
