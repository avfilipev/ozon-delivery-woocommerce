<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

/**
 * Выбор покупателя и сообщения плагина в сессии WooCommerce.
 *
 * Здесь же живут ошибки расчёта. Правило 5: их нельзя выбрасывать через
 * `wc_add_notice(..., 'error')` — это ловит `check_cart_items()` и валит весь
 * чекаут. Сообщение показывается своей строкой в таблице доставки.
 *
 * Сессии нет в админке, в WP-CLI и в REST-запросах, поэтому каждое обращение
 * проверяет её наличие.
 */
final class SessionState {

	private const POINT_KEY = 'ozon_delivery_point_id';

	private const NOTICE_KEY = 'ozon_delivery_notice';

	public function chosen_point_id(): ?int {
		$value = (int) $this->get( self::POINT_KEY );

		return $value > 0 ? $value : null;
	}

	public function choose_point( int $delivery_point_id ): void {
		$this->set( self::POINT_KEY, $delivery_point_id > 0 ? $delivery_point_id : 0 );
	}

	public function forget_point(): void {
		$this->set( self::POINT_KEY, 0 );
	}

	public function remember_notice( string $message ): void {
		$message = trim( $message );

		if ( '' === $message ) {
			return;
		}

		$this->set( self::NOTICE_KEY, $message );
	}

	/**
	 * Сообщение живёт, пока живёт причина.
	 *
	 * Забирать его по разу нельзя: WooCommerce кэширует тарифы по отпечатку
	 * пакета, и пока покупатель не сменил ни точку, ни телефон, расчёт
	 * повторно не запускается — новое сообщение взяться неоткуда. Покупатель
	 * видел объяснение один раз, а потом оставался с пустой строкой доставки
	 * и без подсказки, что делать.
	 */
	public function current_notice(): ?string {
		$message = (string) $this->get( self::NOTICE_KEY );

		return '' === $message ? null : $message;
	}

	/**
	 * Снимает сообщение. Зовёт тот, кто устранил причину: расчёт, отдавший
	 * тариф, или смена выбора.
	 */
	public function forget_notice(): void {
		$this->set( self::NOTICE_KEY, '' );
	}

	private function get( string $key ): mixed {
		$session = $this->session();

		return null === $session ? null : $session->get( $key );
	}

	private function set( string $key, mixed $value ): void {
		$this->session()?->set( $key, $value );
	}

	private function session(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		/**
		 * Стабы обещают, что session есть всегда, но до woocommerce_init её
		 * ещё нет — поэтому тип намеренно не сужается.
		 *
		 * @var mixed $woocommerce
		 */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) ) {
			return null;
		}

		$session = $woocommerce->session ?? null;

		return is_object( $session ) ? $session : null;
	}
}
