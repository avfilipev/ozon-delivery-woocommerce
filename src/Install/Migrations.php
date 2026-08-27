<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

final class Migrations {

	public const OPTION_NAME = 'ozon_delivery_db_version';

	public const CURRENT_VERSION = '1.1.0';

	/**
	 * Имя таблицы пунктов выдачи без префикса WordPress.
	 */
	public const POINTS_TABLE = 'ozon_delivery_points';

	/**
	 * Приводит схему БД к текущей версии плагина.
	 */
	public function run(): void {
		$installed = get_option( self::OPTION_NAME, false );

		if ( self::CURRENT_VERSION === $installed ) {
			return;
		}

		$this->create_points_table();

		update_option( self::OPTION_NAME, self::CURRENT_VERSION );
	}

	/**
	 * Полное имя таблицы пунктов выдачи с префиксом.
	 */
	public static function points_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::POINTS_TABLE;
	}

	/**
	 * Каталог ПВЗ хранится у себя: delivery-point/list отдаёт только
	 * идентификаторы, а адреса и координаты добираются пачками через
	 * delivery-point/info. Без своей таблицы карту и поиск не построить.
	 *
	 * Деньги лежат в минорных единицах целым числом — правило 9.
	 */
	private function create_points_table(): void {
		global $wpdb;

		$table   = self::points_table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			delivery_point_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			delivery_point_number VARCHAR(64) NOT NULL DEFAULT '',
			type VARCHAR(64) NOT NULL DEFAULT '',
			full_address TEXT NULL,
			city VARCHAR(190) NOT NULL DEFAULT '',
			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			is_bulky TINYINT(1) NOT NULL DEFAULT 0,
			storage_period_days SMALLINT UNSIGNED NULL,
			fitting_rooms_count SMALLINT UNSIGNED NULL,
			shipment_method_ids TEXT NULL,
			min_weight_g INT UNSIGNED NULL,
			max_weight_g INT UNSIGNED NULL,
			max_length_mm INT UNSIGNED NULL,
			max_width_mm INT UNSIGNED NULL,
			max_height_mm INT UNSIGNED NULL,
			min_price_minor BIGINT NULL,
			max_price_minor BIGINT NULL,
			price_currency VARCHAR(8) NOT NULL DEFAULT '',
			schedule LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (delivery_point_id),
			KEY is_active (is_active),
			KEY city (city),
			KEY coordinates (latitude, longitude),
			KEY updated_at (updated_at)
		) {$collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}
}
