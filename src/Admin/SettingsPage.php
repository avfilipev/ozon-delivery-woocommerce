<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use WC_Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Обвязка Settings под WooCommerce Settings API. Не юнит-тестируется —
 * WC_Settings_Page существует только при загруженном WooCommerce,
 * проверяется вручную в wp-env (Настройки → Ozon Доставка).
 */
final class SettingsPage extends WC_Settings_Page {

	private const SECRET_FIELD_TYPE = 'ozon_secret';

	private Settings $settings;

	public function __construct() {
		$this->id       = 'ozon_delivery';
		$this->label    = __( 'Ozon Доставка', 'ozon-delivery-for-woocommerce' );
		$this->settings = new Settings();

		add_action( 'woocommerce_admin_field_' . self::SECRET_FIELD_TYPE, array( $this, 'render_secret_field' ) );
		add_action( 'woocommerce_update_option_' . self::SECRET_FIELD_TYPE, array( $this, 'save_secret_field' ) );

		parent::__construct();
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	protected function get_settings_for_default_section(): array {
		$fields = array();

		foreach ( $this->settings->get_fields() as $field ) {
			$fields[] = array_merge( $field, array( 'title' => $this->field_title( $field['id'] ) ) );
		}

		return $fields;
	}

	/**
	 * @param array<string, string> $option Определение поля из get_settings_for_default_section().
	 */
	public function render_secret_field( array $option ): void {
		$existing = (string) get_option( $option['id'], '' );
		$masked   = $this->settings->mask_secret_for_display( $existing );

		printf(
			'<tr valign="top"><th scope="row" class="titledesc"><label for="%1$s">%2$s</label></th>' .
			'<td class="forminp"><fieldset><input type="password" class="regular-text" name="%1$s" id="%1$s" ' .
			'value="" autocomplete="new-password" placeholder="%3$s" /><p class="description">%4$s</p></fieldset></td></tr>',
			esc_attr( $option['id'] ),
			esc_html( $option['title'] ),
			esc_attr( '' !== $masked ? $masked : __( 'не задано', 'ozon-delivery-for-woocommerce' ) ),
			esc_html__( 'Оставьте пустым, чтобы не менять сохранённое значение.', 'ozon-delivery-for-woocommerce' )
		);
	}

	/**
	 * @param array<string, string> $option Определение поля из get_settings_for_default_section().
	 */
	public function save_secret_field( array $option ): void {
		// Nonce 'woocommerce-settings' уже проверен в WC_Admin_Settings::save() до вызова этого хука.
		$posted   = isset( $_POST[ $option['id'] ] ) ? wc_clean( wp_unslash( $_POST[ $option['id'] ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$existing = (string) get_option( $option['id'], '' );

		$sanitized = $this->settings->sanitize(
			array( $option['id'] => (string) $posted ),
			array( $option['id'] => $existing )
		);

		update_option( $option['id'], $sanitized[ $option['id'] ] );
	}

	private function field_title( string $id ): string {
		switch ( $id ) {
			case Settings::FIELD_CLIENT_ID:
				return __( 'Client ID', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_CLIENT_SECRET:
				return __( 'Client Secret', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_SCOPE:
				return __( 'Scope', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_SHIPMENT_METHOD_ID:
				return __( 'Shipment Method ID', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_DRY_RUN:
				return __( 'Dry-run: не отправлять запросы на запись', 'ozon-delivery-for-woocommerce' );
			default:
				return $id;
		}
	}
}
