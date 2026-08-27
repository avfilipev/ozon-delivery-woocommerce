<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Order\Creator;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\PostingStatus;
use Spoki\OzonDelivery\Order\StatusSync;

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

	public const APPROVE_ACTION = 'ozon_delivery_approve_posting';

	public const LABEL_ACTION = 'ozon_delivery_download_label';

	public const REFRESH_ACTION = 'ozon_delivery_refresh_status';

	private const SCREEN_LEGACY = 'shop_order';

	private const SCREEN_HPOS = 'woocommerce_page_wc-orders';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_post_' . self::PUSH_ACTION, array( $this, 'handle_push' ) );
		add_action( 'admin_post_' . self::APPROVE_ACTION, array( $this, 'handle_approve' ) );
		add_action( 'admin_post_' . self::LABEL_ACTION, array( $this, 'handle_label' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
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
			$this->render_posting_actions( $order );

			return;
		}

		$this->render_push_button( (int) $order->get_id() );
	}

	/**
	 * Действия над уже переданным отправлением.
	 */
	private function render_posting_actions( object $order ): void {
		$status = new PostingStatus( (string) $order->get_meta( Meta::POSTING_STATUS ) );

		echo '<p><strong>' . esc_html__( 'Статус Ozon', 'ozon-delivery-for-woocommerce' ) . '</strong><br />';
		echo esc_html( $status->label() ) . '</p>';

		$order_id = (int) $order->get_id();

		echo '<p>';
		$this->render_action_link( self::REFRESH_ACTION, $order_id, __( 'Обновить статус', 'ozon-delivery-for-woocommerce' ) );

		// Подтверждать имеет смысл только созданное отправление, а этикетка
		// доступна только после подтверждения.
		if ( $status->can_be_approved() ) {
			$this->render_action_link( self::APPROVE_ACTION, $order_id, __( 'Подтвердить к отгрузке', 'ozon-delivery-for-woocommerce' ), 'button-primary' );
		}

		if ( $status->label_available() ) {
			$this->render_action_link( self::LABEL_ACTION, $order_id, __( 'Скачать этикетку', 'ozon-delivery-for-woocommerce' ), 'button-primary' );
		}

		echo '</p>';

		if ( $status->needs_attention() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Ozon не смог подтвердить отправление. Проверьте баланс кабинета и данные заказа.', 'ozon-delivery-for-woocommerce' )
			);
		}
	}

	private function render_action_link( string $action, int $order_id, string $label, string $style = 'button' ): void {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&order_id=' . $order_id ),
			$action . '_' . $order_id
		);

		printf(
			'<a href="%s" class="button %s" style="margin-right:6px">%s</a>',
			esc_url( $url ),
			esc_attr( $style ),
			esc_html( $label )
		);
	}

	public function handle_approve(): void {
		$order = $this->authorised_order( self::APPROVE_ACTION );

		if ( null === $order ) {
			return;
		}

		$posting_number = Meta::posting_number( $order );

		if ( null !== $posting_number ) {
			$result = ( new Postings( ClientFactory::create() ) )->approve( $posting_number );

			$order->add_order_note(
				$result->succeeded
					? __( 'Отправление подтверждено к отгрузке.', 'ozon-delivery-for-woocommerce' )
					: sprintf( 'Ozon Доставка: %s', $result->message )
			);

			// Настоящий результат подтверждения смотрится через posting/info —
			// так велит документация Ozon.
			StatusSync::create()->sync_order( $order );
		}

		$this->back();
	}

	public function handle_refresh(): void {
		$order = $this->authorised_order( self::REFRESH_ACTION );

		if ( null !== $order ) {
			StatusSync::create()->sync_order( $order );
		}

		$this->back();
	}

	public function handle_label(): void {
		$order = $this->authorised_order( self::LABEL_ACTION );

		if ( null === $order ) {
			return;
		}

		$posting_number = Meta::posting_number( $order );

		if ( null === $posting_number ) {
			$this->back();

			return;
		}

		try {
			$label = ( new Postings( ClientFactory::create() ) )->label( $posting_number );
		} catch ( ApiException $e ) {
			$order->add_order_note( sprintf( 'Ozon Доставка: %s', $e->getMessage() ) );

			$this->back();

			return;
		}

		nocache_headers();
		header( 'Content-Type: ' . $label->content_type );
		header( 'Content-Disposition: attachment; filename="' . $label->filename . '"' );

		echo $label->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- это файл, а не разметка.
		exit;
	}

	/**
	 * Общая проверка прав и nonce для действий над заказом.
	 */
	private function authorised_order( string $action ): ?object {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-delivery-for-woocommerce' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( $action . '_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			$this->back();

			return null;
		}

		return $order;
	}

	private function back(): void {
		$referer = wp_get_referer();

		wp_safe_redirect( false === $referer ? admin_url( 'admin.php?page=wc-orders' ) : $referer );
		exit;
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
		$order = $this->authorised_order( self::PUSH_ACTION );

		if ( null !== $order ) {
			Creator::create()->push( $order );
		}

		$this->back();
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
