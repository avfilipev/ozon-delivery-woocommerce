<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Returns;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Order\ReturnInfo;

/**
 * Экран возвратов Ozon в админке WooCommerce.
 *
 * Список возвратов и штрихкод для их получения. Штрихкод берётся прямо
 * перед приёмкой: срок действия указан в самом файле.
 *
 * Обвязка WordPress, юнит-тестами не покрыта — проверяется в wp-env.
 */
final class ReturnsScreen {

	public const SLUG = 'ozon-delivery-returns';

	public const BARCODE_ACTION = 'ozon_delivery_return_barcode';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::BARCODE_ACTION, array( $this, 'handle_barcode' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Возвраты Ozon', 'ozon-delivery-for-woocommerce' ),
			__( 'Возвраты Ozon', 'ozon-delivery-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Возвраты Ozon', 'ozon-delivery-for-woocommerce' ) . '</h1>';

		$this->render_barcode_button();

		try {
			$page = ( new Returns( ClientFactory::create() ) )->search();
		} catch ( ApiException $e ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div></div>',
				esc_html( $e->getMessage() )
			);

			return;
		}

		if ( array() === $page->returns ) {
			echo '<p>' . esc_html__( 'Возвратов нет.', 'ozon-delivery-for-woocommerce' ) . '</p></div>';

			return;
		}

		$this->render_table( $page->returns );

		if ( ! $page->is_last() ) {
			echo '<p class="description">'
				. esc_html__( 'Показана первая страница списка. Остальные доступны через API.', 'ozon-delivery-for-woocommerce' )
				. '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param ReturnInfo[] $returns
	 */
	private function render_table( array $returns ): void {
		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array(
			__( 'Возврат', 'ozon-delivery-for-woocommerce' ),
			__( 'Заказ', 'ozon-delivery-for-woocommerce' ),
			__( 'Статус', 'ozon-delivery-for-woocommerce' ),
			__( 'Где сейчас', 'ozon-delivery-for-woocommerce' ),
			__( 'Причина', 'ozon-delivery-for-woocommerce' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $returns as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item->return_number ) . '</td>';
			echo '<td>' . esc_html( $item->return_external_id ) . '</td>';
			echo '<td>' . esc_html( $item->status->label() );

			if ( $item->status->needs_attention() ) {
				echo ' <span class="dashicons dashicons-warning" aria-hidden="true"></span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( $item->current_placement_name ) . '</td>';
			echo '<td>' . esc_html( $item->cancellation_reason ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_barcode_button(): void {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::BARCODE_ACTION ),
			self::BARCODE_ACTION
		);

		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Скачать штрихкод получения возвратов', 'ozon-delivery-for-woocommerce' )
		);

		echo '<p class="description">'
			. esc_html__( 'Срок действия указан в самом файле: скачивайте штрихкод непосредственно перед получением возвратов.', 'ozon-delivery-for-woocommerce' )
			. '</p>';
	}

	public function handle_barcode(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-delivery-for-woocommerce' ) );
		}

		check_admin_referer( self::BARCODE_ACTION );

		try {
			$barcode = ( new Returns( ClientFactory::create() ) )->download_barcode();
		} catch ( ApiException $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $barcode->content_type );
		header( 'Content-Disposition: attachment; filename="' . $barcode->filename . '"' );

		echo $barcode->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- это файл, а не разметка.
		exit;
	}
}
