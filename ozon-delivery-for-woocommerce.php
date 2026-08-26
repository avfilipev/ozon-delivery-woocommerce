<?php
/**
 * Plugin Name: Ozon Доставка для WooCommerce
 * Description: Интеграция Ozon Delivery API в WooCommerce.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * WC requires at least: 8.2
 * Requires Plugins: woocommerce
 * Author: Spoki
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ozon-delivery-for-woocommerce
 * Domain Path: /languages
 *
 * @package Spoki\OzonDelivery
 */

declare(strict_types=1);

namespace Spoki\OzonDelivery;

use Spoki\OzonDelivery\Support\Requirements;

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( new Plugin( __FILE__ ), 'activate' ) );

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Проверяет версии окружения и либо загружает плагин, либо показывает
 * понятное сообщение вместо фатальной ошибки.
 */
function bootstrap(): void {
	global $wp_version;

	$errors = ( new Requirements() )->check(
		PHP_VERSION,
		$wp_version,
		defined( 'WC_VERSION' ) ? \WC_VERSION : null
	);

	if ( array() !== $errors ) {
		add_action(
			'admin_notices',
			static function () use ( $errors ): void {
				render_requirements_notice( $errors );
			}
		);
		return;
	}

	( new Plugin( __FILE__ ) )->boot();
}

/**
 * @param string[] $errors
 */
function render_requirements_notice( array $errors ): void {
	foreach ( $errors as $error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}
