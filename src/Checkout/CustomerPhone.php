<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

/**
 * Телефон покупателя на чекауте.
 *
 * Ozon узнаёт покупателя только по телефону, и без него доставка не считается
 * вовсе. Взять его из WC()->customer недостаточно: обработчик пересчёта
 * чекаута, WC_AJAX::update_order_review(), переносит в покупателя лишь
 * страну, регион, индекс, город и адрес — телефона среди них нет. Форма его
 * присылает, но целой строкой в post_data, и разбирать её приходится самим.
 *
 * Порядок источников — от свежего к сохранённому:
 *   1. поле формы (оформление заказа шлёт поля напрямую);
 *   2. post_data (пересчёт корзины и доставки);
 *   3. телефон, сохранённый у покупателя (обычная загрузка страницы).
 */
final class CustomerPhone {

	private const FIELD = 'billing_phone';

	public function resolve(): string {
		$phone = $this->from_posted_field();

		if ( '' === $phone ) {
			$phone = $this->from_serialised_form();
		}

		if ( '' === $phone ) {
			$phone = $this->from_customer();
		}

		/**
		 * Телефон, по которому проверяется покупатель и считается доставка.
		 *
		 * @param string $phone Номер как его удалось определить.
		 */
		return (string) apply_filters( 'ozon_delivery_customer_phone', $phone );
	}

	/**
	 * Нонс проверяет сам WooCommerce до расчёта доставки: и update_order_review,
	 * и оформление заказа делают это раньше, чем дело доходит до тарифов.
	 * Здесь номер только читается — ничего не сохраняется и не выполняется.
	 */
	private function from_posted_field(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::FIELD ] ) || ! is_string( $_POST[ self::FIELD ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $this->clean( wp_unslash( $_POST[ self::FIELD ] ) );
	}

	/**
	 * post_data — вся форма чекаута одной строкой, как её сериализовал jQuery.
	 */
	private function from_serialised_form(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['post_data'] ) || ! is_string( $_POST['post_data'] ) ) {
			return '';
		}

		$fields = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$form = (string) wp_unslash( $_POST['post_data'] );

		parse_str( $form, $fields );

		$phone = $fields[ self::FIELD ] ?? '';

		return is_string( $phone ) ? $this->clean( $phone ) : '';
	}

	private function from_customer(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		/** @var mixed $woocommerce */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) ) {
			return '';
		}

		$customer = $woocommerce->customer ?? null;

		if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_billing_phone' ) ) ) {
			return '';
		}

		return $this->clean( (string) $customer->get_billing_phone() );
	}

	private function clean( mixed $value ): string {
		return trim( (string) sanitize_text_field( is_string( $value ) ? $value : '' ) );
	}
}
