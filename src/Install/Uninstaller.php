<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Api\CookieJar;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Jobs\SyncPointsJob;
use Spoki\OzonDelivery\Points\CatalogSync;

/**
 * Полная уборка за плагином: опции, кэши, своя таблица и фоновые задачи.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */
final class Uninstaller {

	/**
	 * Опции, не входящие в экран настроек.
	 *
	 * @var string[]
	 */
	private const SERVICE_OPTIONS = array(
		Migrations::OPTION_NAME,
		CatalogSync::STATE_OPTION,
	);

	/**
	 * Транзиенты с секретами и служебными данными.
	 *
	 * @var string[]
	 */
	private const TRANSIENTS = array(
		TokenStore::TRANSIENT,
		CookieJar::TRANSIENT,
		'ozon_delivery_connection_result',
	);

	public function run(): void {
		$this->delete_options();
		$this->delete_transients();
		$this->drop_tables();

		SyncPointsJob::unschedule();
	}

	/**
	 * Список полей берётся из Settings, а не дублируется здесь: иначе новая
	 * настройка однажды переживёт удаление плагина.
	 */
	private function delete_options(): void {
		foreach ( ( new Settings() )->get_fields() as $field ) {
			delete_option( $field['id'] );
		}

		foreach ( self::SERVICE_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	private function delete_transients(): void {
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
	}

	private function drop_tables(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . Migrations::points_table() );
	}
}
