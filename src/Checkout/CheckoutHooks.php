<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Shipping\MethodPickup;
use Spoki\OzonDelivery\Shipping\Methods;

/**
 * Подключение плагина к чекауту WooCommerce.
 *
 * Регистрация метода доставки, перенос выбранного пункта выдачи в заказ и
 * показ сообщений плагина. Правило 5: сообщения показываются своей строкой,
 * а не через wc_add_notice(..., 'error') — иначе их поймает
 * check_cart_items() и уронит оформление.
 */
final class CheckoutHooks {

	public function __construct(
		private readonly SessionState $state = new SessionState(),
		private readonly Repository $points = new Repository(),
		private readonly CustomerPhone $phone = new CustomerPhone(),
		private readonly OrderQuote $quote = new OrderQuote()
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_choice_to_packages' ) );

		( new PointPicker() )->register();
		( new PickerField() )->register();
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_point_to_order' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render_notice' ) );
	}

	/**
	 * @param array<string, string> $methods
	 *
	 * @return array<string, string>
	 */
	public function register_shipping_method( array $methods ): array {
		$methods[ Methods::PICKUP ] = MethodPickup::class;

		return $methods;
	}

	/**
	 * Кладёт выбор покупателя в пакет доставки.
	 *
	 * WooCommerce кэширует тарифы по хешу пакета. Выбранный ПВЗ и телефон
	 * живут в сессии и в пакет не входят, поэтому без этого хеш не менялся:
	 * покупатель выбирал точку, а цена не появлялась — отдавался прошлый
	 * пустой результат. Оба значения влияют на тариф, значит оба обязаны
	 * входить в хеш.
	 *
	 * @param array<int, array<string, mixed>> $packages
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_choice_to_packages( array $packages ): array {
		$point_id = $this->state->chosen_point_id() ?? 0;
		$phone    = $this->phone->resolve();

		foreach ( array_keys( $packages ) as $index ) {
			$packages[ $index ]['ozon_delivery_point_id'] = $point_id;
			$packages[ $index ]['ozon_delivery_phone']    = $phone;
		}

		return $packages;
	}

	/**
	 * Переносит выбранный пункт выдачи в заказ.
	 *
	 * Точка могла исчезнуть из каталога между выбором и оформлением, а метод
	 * доставки могли переключить на другой — оба случая проверяются.
	 */
	public function save_point_to_order( object $order ): void {
		if ( ! $this->pickup_is_chosen() ) {
			return;
		}

		$point_id = $this->state->chosen_point_id();

		if ( null === $point_id ) {
			return;
		}

		$point = $this->points->find( $point_id );

		if ( null === $point ) {
			return;
		}

		Meta::save_point( $order, $point );

		// Разбивку показывает метабокс заказа. Сетевого запроса здесь обычно
		// нет: тот же расчёт только что сделал метод доставки.
		$this->quote->save( $order, $point_id );
	}

	/**
	 * Сообщение плагина. Держится, пока держится причина: снимает его расчёт,
	 * которому удалось отдать тариф.
	 */
	public function current_notice(): ?string {
		return $this->state->current_notice();
	}

	public function render_notice(): void {
		$notice = $this->current_notice();

		if ( null === $notice ) {
			return;
		}

		printf(
			'<tr class="ozon-delivery-notice"><td colspan="2"><small>%s</small></td></tr>',
			esc_html( $notice )
		);
	}

	/**
	 * Выбран ли на чекауте именно наш метод.
	 */
	private function pickup_is_chosen(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		/** @var mixed $woocommerce */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) ) {
			return false;
		}

		$session = $woocommerce->session ?? null;

		if ( ! is_object( $session ) || ! is_callable( array( $session, 'get' ) ) ) {
			return false;
		}

		$chosen = $session->get( 'chosen_shipping_methods', array() );

		if ( ! is_array( $chosen ) ) {
			return false;
		}

		foreach ( $chosen as $method ) {
			if ( is_string( $method ) && str_starts_with( $method, Methods::PICKUP ) ) {
				return true;
			}
		}

		return false;
	}
}
