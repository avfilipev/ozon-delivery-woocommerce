<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

final class Migrations {

	public const OPTION_NAME = 'ozon_delivery_db_version';

	public const CURRENT_VERSION = '1.0.0';

	/**
	 * Приводит схему БД к текущей версии плагина. В фазе 0 своих таблиц нет,
	 * поэтому шаг — только фиксация версии в опции для будущих миграций.
	 */
	public function run(): void {
		$installed = get_option( self::OPTION_NAME, false );

		if ( self::CURRENT_VERSION === $installed ) {
			return;
		}

		update_option( self::OPTION_NAME, self::CURRENT_VERSION );
	}
}
