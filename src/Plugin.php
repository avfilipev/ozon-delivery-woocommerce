<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Spoki\OzonDelivery\Admin\OrderMetabox;
use Spoki\OzonDelivery\Admin\ReturnsScreen;
use Spoki\OzonDelivery\Admin\SettingsPage;
use Spoki\OzonDelivery\Checkout\CheckoutHooks;
use Spoki\OzonDelivery\Cli\Commands;
use Spoki\OzonDelivery\Install\EnvFile;
use Spoki\OzonDelivery\Install\Migrations;
use Spoki\OzonDelivery\Jobs\SyncPointsJob;
use Spoki\OzonDelivery\Jobs\SyncStatusesJob;

/**
 * Оркестрация плагина после того, как Requirements подтвердил, что версии
 * PHP/WordPress/WooCommerce в порядке. Вызывается из главного файла плагина.
 */
final class Plugin {

	public function __construct( private readonly string $plugin_file ) {
	}

	public function boot(): void {
		// Ключи из .env.local — удобство локальной разработки. Заданное в
		// админке не перетирается, а когда ключи уже есть, файл не читается.
		EnvFile::create()->fill_missing_options();

		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_settings_page' ) );

		// Без слушателя Action Scheduler поставит задачу в очередь, а выполнять
		// её будет некому. Задача собирается лениво, уже в момент выполнения.
		( new CheckoutHooks() )->register();
		( new OrderMetabox() )->register();
		( new ReturnsScreen() )->register();

		Commands::register();

		add_action(
			SyncPointsJob::HOOK,
			static function (): void {
				SyncPointsJob::create()->run();
			}
		);

		add_action(
			SyncStatusesJob::HOOK,
			static function (): void {
				SyncStatusesJob::create()->run();
			}
		);
	}

	/**
	 * Совместимость с HPOS (custom_order_tables) заявлена, с блочным чекаутом
	 * (cart_checkout_blocks) — нет, интеграция через Store API вынесена в
	 * фазу 5 по docs/PLAN.md.
	 */
	public function declare_compatibility(): void {
		FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->plugin_file, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $this->plugin_file, false );
	}

	/**
	 * @param array<int, \WC_Settings_Page> $pages
	 * @return array<int, \WC_Settings_Page>
	 */
	public function register_settings_page( array $pages ): array {
		$pages[] = new SettingsPage();

		return $pages;
	}

	public function activate(): void {
		( new Migrations() )->run();

		SyncPointsJob::schedule_daily();
		SyncStatusesJob::schedule_hourly();
	}

	/**
	 * Фоновые задачи снимаются с расписания, чтобы отключённый плагин не
	 * оставлял за собой работу в очереди.
	 */
	public function deactivate(): void {
		SyncPointsJob::unschedule();
		SyncStatusesJob::unschedule();
	}
}
