<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Shipping\Methods;


/**
 * Поле выбора пункта выдачи на чекауте.
 *
 * Рендер и подключение скрипта. Логика поиска и выбора живёт в PointPicker,
 * здесь только обвязка WooCommerce, поэтому юнит-тестами не покрыто —
 * проверяется в wp-env.
 */
final class PickerField {

	private const HANDLE = 'ozon-delivery-point-picker';

	public function __construct( private readonly PointPicker $picker = new PointPicker() ) {
	}

	public function register(): void {
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/checkout-point-picker.js', dirname( __DIR__ ) . '/ozon-delivery-for-woocommerce.php' ),
			array(),
			'0.1.0',
			true
		);

		wp_localize_script(
			self::HANDLE,
			'ozonDeliveryPicker',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'searchAction' => PointPicker::AJAX_SEARCH,
				'chooseAction' => PointPicker::AJAX_CHOOSE,
				'searchNonce'  => wp_create_nonce( PointPicker::AJAX_SEARCH ),
				'chooseNonce'  => wp_create_nonce( PointPicker::AJAX_CHOOSE ),
				'i18n'         => array(
					'searching'    => __( 'Ищем пункты выдачи…', 'ozon-delivery-for-woocommerce' ),
					'nothingFound' => __( 'В этом городе пунктов выдачи не нашлось.', 'ozon-delivery-for-woocommerce' ),
					'enterCity'    => __( 'Укажите город.', 'ozon-delivery-for-woocommerce' ),
					'saving'       => __( 'Сохраняем выбор…', 'ozon-delivery-for-woocommerce' ),
					'chosen'       => __( 'Выбран пункт выдачи:', 'ozon-delivery-for-woocommerce' ),
					'failed'       => __( 'Не удалось получить список пунктов выдачи.', 'ozon-delivery-for-woocommerce' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! $this->pickup_is_available() ) {
			return;
		}

		$chosen = $this->picker->chosen();

		echo '<tr class="ozon-delivery-picker"><th>';
		echo esc_html__( 'Пункт выдачи Ozon', 'ozon-delivery-for-woocommerce' );
		echo '</th><td>';

		printf(
			'<p><input type="text" id="ozon-delivery-city" placeholder="%s" /> '
			. '<button type="button" class="button" id="ozon-delivery-search">%s</button></p>',
			esc_attr__( 'Город', 'ozon-delivery-for-woocommerce' ),
			esc_html__( 'Найти', 'ozon-delivery-for-woocommerce' )
		);

		echo '<p id="ozon-delivery-status">';

		if ( null !== $chosen ) {
			printf(
				'%s %s',
				esc_html__( 'Выбран пункт выдачи:', 'ozon-delivery-for-woocommerce' ),
				esc_html( (string) $chosen['address'] )
			);
		}

		echo '</p>';
		echo '<ul id="ozon-delivery-points" class="ozon-delivery-points"></ul>';
		echo '</td></tr>';
	}

	/**
	 * Показываем поле только когда наш метод вообще предложен покупателю:
	 * иначе на чекауте появится непонятный блок.
	 */
	private function pickup_is_available(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		/** @var mixed $woocommerce */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) || ! is_callable( array( $woocommerce, 'shipping' ) ) ) {
			return false;
		}

		foreach ( $woocommerce->shipping()->get_packages() as $package ) {
			foreach ( array_keys( $package['rates'] ?? array() ) as $rate_id ) {
				if ( is_string( $rate_id ) && str_starts_with( $rate_id, Methods::PICKUP ) ) {
					return true;
				}
			}
		}

		// Тарифа ещё нет — но именно поэтому поле и нужно: без выбранной точки
		// расчёт невозможен.
		return $this->method_is_enabled();
	}

	private function method_is_enabled(): bool {
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] ?? array() as $method ) {
				if ( Methods::PICKUP === $method->id && 'yes' === $method->enabled ) {
					return true;
				}
			}
		}

		return false;
	}
}
