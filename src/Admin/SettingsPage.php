<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Jobs\SyncPointsJob;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Support\Logger;
use WC_Settings_Page;


/**
 * Обвязка Settings под WooCommerce Settings API. Не юнит-тестируется —
 * WC_Settings_Page существует только при загруженном WooCommerce,
 * проверяется вручную в wp-env (Настройки → Ozon Доставка).
 */
final class SettingsPage extends WC_Settings_Page {

	private const SECRET_FIELD_TYPE = 'ozon_secret';

	private const CONNECTION_FIELD_TYPE = 'ozon_connection';

	private const CATALOG_FIELD_TYPE = 'ozon_catalog';

	public const TEST_ACTION = 'ozon_delivery_test_connection';

	public const SYNC_ACTION = 'ozon_delivery_sync_points';

	private const RESULT_TRANSIENT = 'ozon_delivery_connection_result';

	private Settings $settings;

	public function __construct() {
		$this->id       = 'ozon_delivery';
		$this->label    = __( 'Ozon Доставка', 'ozon-delivery-for-woocommerce' );
		$this->settings = new Settings();

		add_action( 'woocommerce_admin_field_' . self::SECRET_FIELD_TYPE, array( $this, 'render_secret_field' ) );
		add_action( 'woocommerce_update_option_' . self::SECRET_FIELD_TYPE, array( $this, 'save_secret_field' ) );
		add_action( 'woocommerce_admin_field_' . self::CONNECTION_FIELD_TYPE, array( $this, 'render_connection_check' ) );
		add_action( 'woocommerce_admin_field_' . self::CATALOG_FIELD_TYPE, array( $this, 'render_catalog_status' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_connection_check' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_points_sync' ) );

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

		$fields[] = array(
			'id'        => 'ozon_delivery_connection',
			'type'      => self::CONNECTION_FIELD_TYPE,
			'title'     => __( 'Подключение', 'ozon-delivery-for-woocommerce' ),
			'is_option' => false,
		);

		$fields[] = array(
			'id'        => 'ozon_delivery_catalog',
			'type'      => self::CATALOG_FIELD_TYPE,
			'title'     => __( 'Каталог пунктов выдачи', 'ozon-delivery-for-woocommerce' ),
			'is_option' => false,
		);

		return $fields;
	}

	/**
	 * Состояние локального каталога ПВЗ и кнопка запуска синхронизации.
	 */
	public function render_catalog_status(): void {
		$status = new CatalogStatus(
			new Repository(),
			new CatalogSync(
				new DeliveryPoints( ClientFactory::create() ),
				new Repository(),
				new Logger()
			)
		);

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label>' . esc_html__( 'Каталог пунктов выдачи', 'ozon-delivery-for-woocommerce' ) . '</label>';
		echo '</th><td class="forminp">';

		echo '<p>' . esc_html( $status->describe() ) . '</p>';

		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SYNC_ACTION ), self::SYNC_ACTION ) ),
			esc_html__( 'Обновить каталог', 'ozon-delivery-for-woocommerce' )
		);

		echo '<p class="description">';
		esc_html_e(
			'Обход идёт в фоне порциями: каталог у Ozon большой, а адреса добираются отдельными запросами. Обрыв продолжится с того же места.',
			'ozon-delivery-for-woocommerce'
		);
		echo '</p>';

		echo '</td></tr>';
	}

	/**
	 * Запускает обход каталога заново.
	 */
	public function handle_points_sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-delivery-for-woocommerce' ) );
		}

		check_admin_referer( self::SYNC_ACTION );

		SyncPointsJob::create()->start_now();

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=' . $this->id ) );
		exit;
	}

	/**
	 * Кнопка «Проверить подключение» и результат последней проверки.
	 */
	public function render_connection_check(): void {
		$result = get_transient( self::RESULT_TRANSIENT );

		if ( is_array( $result ) ) {
			delete_transient( self::RESULT_TRANSIENT );
		}

		echo '<tr valign="top"><th scope="row" class="titledesc">';
		echo '<label>' . esc_html__( 'Подключение', 'ozon-delivery-for-woocommerce' ) . '</label>';
		echo '</th><td class="forminp">';

		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TEST_ACTION ), self::TEST_ACTION ) ),
			esc_html__( 'Проверить подключение', 'ozon-delivery-for-woocommerce' )
		);

		echo '<p class="description">';
		esc_html_e(
			'Отправляет запрос delivery-point/list с лимитом 1: ничего не создаёт, но проверяет ключи, токен и сеть целиком.',
			'ozon-delivery-for-woocommerce'
		);
		echo '</p>';

		if ( is_array( $result ) && isset( $result['message'] ) ) {
			printf(
				'<div class="notice inline notice-%s" style="margin:10px 0 0"><p>%s</p></div>',
				empty( $result['ok'] ) ? 'error' : 'success',
				esc_html( (string) $result['message'] )
			);
		}

		echo '</td></tr>';
	}

	/**
	 * Обработчик кнопки: гоняет проверку и возвращает администратора обратно
	 * на вкладку настроек с результатом.
	 */
	public function handle_connection_check(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-delivery-for-woocommerce' ) );
		}

		check_admin_referer( self::TEST_ACTION );

		$result = ( new HealthCheck( ClientFactory::create() ) )->run();

		set_transient(
			self::RESULT_TRANSIENT,
			array(
				'ok'      => $result->ok,
				'message' => $result->message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=' . $this->id ) );
		exit;
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
			case Settings::FIELD_DEFAULT_WEIGHT:
				return __( 'Вес по умолчанию (единицы WooCommerce)', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_DEFAULT_LENGTH:
				return __( 'Длина по умолчанию (единицы WooCommerce)', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_DEFAULT_WIDTH:
				return __( 'Ширина по умолчанию (единицы WooCommerce)', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_DEFAULT_HEIGHT:
				return __( 'Высота по умолчанию (единицы WooCommerce)', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_PACKAGING_PADDING:
				return __( 'Запас на упаковку, мм', 'ozon-delivery-for-woocommerce' );
			case Settings::FIELD_DECLARED_PERCENT:
				return __( 'Объявленная стоимость, % от суммы заказа', 'ozon-delivery-for-woocommerce' );
			default:
				return $id;
		}
	}
}
