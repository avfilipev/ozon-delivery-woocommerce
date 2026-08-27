<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Spoki\OzonDelivery\Tests\TestCase;

/**
 * Скрипт выбора ПВЗ подключается через plugins_url() с путём до главного
 * файла плагина. Ошибиться в нём легко и незаметно: WordPress ничего не
 * скажет, скрипт просто отдаст 404, и выбор точки на чекауте молча
 * перестанет работать.
 *
 * Так и случилось: dirname( __DIR__ ) из src/Checkout даёт src/, а не
 * корень плагина.
 */
final class PickerAssetTest extends TestCase {

	private static function plugin_root(): string {
		return dirname( __DIR__, 3 );
	}

	public function test_script_file_exists_where_it_is_expected(): void {
		self::assertFileExists( self::plugin_root() . '/assets/js/checkout-point-picker.js' );
	}

	/**
	 * Точное выражение из PickerField::enqueue(): второй аргумент
	 * plugins_url() обязан указывать на существующий главный файл плагина,
	 * иначе URL скрипта соберётся от неверного каталога.
	 */
	public function test_base_path_used_for_the_url_points_at_the_plugin_file(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/src/Checkout/PickerField.php' ); // phpcs:ignore

		self::assertMatchesRegularExpression(
			'/dirname\(\s*__DIR__\s*,\s*2\s*\)/',
			$source,
			'Главный файл лежит на два уровня выше src/Checkout.'
		);
	}

	public function test_plugin_file_is_two_levels_above_the_checkout_namespace(): void {
		$from_checkout = dirname( self::plugin_root() . '/src/Checkout/PickerField.php', 3 );

		self::assertFileExists( $from_checkout . '/ozon-delivery-for-woocommerce.php' );
	}
}
