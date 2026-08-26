<?php
/**
 * Удаление плагина: чистит все опции в wp_options.
 *
 * @package Spoki\OzonDelivery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/vendor/autoload.php';

( new \Spoki\OzonDelivery\Install\Uninstaller() )->run();
