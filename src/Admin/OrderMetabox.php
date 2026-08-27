<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Order\Creator;
use Spoki\OzonDelivery\Order\Meta;

/**
 * Блок «Ozon Доставка» на экране заказа.
 *
 * Показывает выбранный пункт выдачи, расчёт, идентификаторы Ozon и ошибку
 * последней попытки. Кнопка передаёт заказ в Ozon вручную.
 *
 * Обвязка WordPress, юнит-тестами не покрыта — проверяется в wp-env.
 */
final class OrderMetabox {

	public const PUSH_ACTION = 'ozon_delivery_push_order';

	private const SCREEN_LEGACY = 'shop_order';

	private const SCREEN_HPOS = 'woocommerce_page_wc-orders';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_post_' . self::PUSH_ACTION, array( $this, 'handle_push' ) );
	}

	public function add(): void {
		// HPOS и старое хранилище дают разные экраны — регистрируем оба.
		foreach ( array( self::SCREEN_LEGACY, self::SCREEN_HPOS ) as $screen ) {
			add_meta_box(
				'ozon-delivery-order',
				__( 'Ozon Доставка', 'ozon-delivery-for-woocommerce' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param mixed $post_or_order Пост или заказ, в зависимости от хранилища.
	 */
	public function render( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );

		if ( null === $order ) {
			return;
		}

		$point_id = Meta::point_id( $order );

		if ( null === $point_id ) {
			echo '<p>' . esc_html__( 'Пункт выдачи Ozon в заказе не выбран.', 'ozon-delivery-for-woocommerce' ) . '</p>';

			return;
		}

		echo '<p><strong>' . esc_html__( 'Пункт выдачи', 'ozon-delivery-for-woocommerce' ) . '</strong><br />';
		echo esc_html( (string) $order->get_meta( Meta::POINT_ADDRESS ) ) . '</p>';

		$this->render_row( __( 'Стоимость доставки', 'ozon-delivery-for-woocommerce' ), (string) $order->get_meta( Meta::DELIVERY_COST ) );
		$this->render_row( __( 'Страховка', 'ozon-delivery-for-woocommerce' ), (string) $order->get_meta( Meta::INSURANCE_COST ) );
		$this->render_row( __( 'Срок, дней', 'ozon-delivery-for-woocommerce' ), (string) $order->get_meta( Meta::DELIVERY_DAYS ) );
		$this->render_row( __( 'Заказ в Ozon', 'ozon-delivery-for-woocommerce' ), (string) Meta::order_number( $order ) );
		$this->render_row( __( 'Отправление', 'ozon-delivery-for-woocommerce' ), (string) Meta::posting_number( $order ) );

		$error = Meta::error( $order );

		if ( null !== $error ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html( $error )
			);
		}

		if ( null !== Meta::order_number( $order ) ) {
			echo '<p>' . esc_html__( 'Заказ уже передан в Ozon.', 'ozon-delivery-for-woocommerce' ) . '</p>';

			return;
		}

		$this->render_push_button( (int) $order->get_id() );
	}

	private function render_push_button( int $order_id ): void {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::PUSH_ACTION . '&order_id=' . $order_id ),
			self::PUSH_ACTION . '_' . $order_id
		);

		printf( '<p><a href="%s" class="button button-primary">%s</a></p>', esc_url( $url ), esc_html__( 'Передать в Ozon', 'ozon-delivery-for-woocommerce' ) );

		if ( ClientFactory::is_dry_run() ) {
			echo '<p class="description">'
				. esc_html__( 'Включён dry-run: заказ не уйдёт в Ozon, запрос только запишется в журнал.', 'ozon-delivery-for-woocommerce' )
				. '</p>';
		}
	}

	public function handle_push(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-delivery-for-woocommerce' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( self::PUSH_ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( $order ) {
			Creator::create()->push( $order );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}

	private function render_row( string $label, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		printf( '<p><strong>%s</strong><br />%s</p>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * @param mixed $post_or_order
	 */
	private function resolve_order( $post_or_order ): ?object {
		if ( is_object( $post_or_order ) && is_callable( array( $post_or_order, 'get_meta' ) ) ) {
			return $post_or_order;
		}

		$id = is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0;

		$order = 0 === $id ? false : wc_get_order( $id );

		return $order instanceof \WC_Order ? $order : null;
	}
}
