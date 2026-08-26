<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Support;

final class Requirements {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.4';
	public const MIN_WC  = '8.2';

	/**
	 * @return string[] Список сообщений об ошибках. Пустой массив — все требования выполнены.
	 */
	public function check( string $php_version, string $wp_version, ?string $wc_version ): array {
		$errors = array();

		if ( version_compare( $php_version, self::MIN_PHP, '<' ) ) {
			$errors[] = sprintf(
				'Ozon Доставка для WooCommerce требует PHP %s или новее, установлена %s.',
				self::MIN_PHP,
				$php_version
			);
		}

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$errors[] = sprintf(
				'Ozon Доставка для WooCommerce требует WordPress %s или новее, установлена %s.',
				self::MIN_WP,
				$wp_version
			);
		}

		if ( null === $wc_version ) {
			$errors[] = 'Ozon Доставка для WooCommerce требует активный плагин WooCommerce.';
		} elseif ( version_compare( $wc_version, self::MIN_WC, '<' ) ) {
			$errors[] = sprintf(
				'Ozon Доставка для WooCommerce требует WooCommerce %s или новее, установлена %s.',
				self::MIN_WC,
				$wc_version
			);
		}

		return $errors;
	}
}
