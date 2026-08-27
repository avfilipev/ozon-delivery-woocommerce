<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Points\PointQuery;
use Spoki\OzonDelivery\Points\Repository;

/**
 * Выбор пункта выдачи на чекауте.
 *
 * Поиск идёт по локальному каталогу, а не по API: delivery-point/list не
 * умеет искать по городу и отдаёт только идентификаторы. Пригодность точки
 * под конкретный заказ подтверждается позже, при расчёте.
 */
final class PointPicker {

	public const MAX_RESULTS = 50;

	public const AJAX_SEARCH = 'ozon_delivery_search_points';

	public const AJAX_CHOOSE = 'ozon_delivery_choose_point';

	public function __construct(
		private readonly Repository $points = new Repository(),
		private readonly SessionState $state = new SessionState()
	) {
	}

	public function register(): void {
		foreach ( array( self::AJAX_SEARCH, self::AJAX_CHOOSE ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle_' . $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $city ): array {
		$city = trim( $city );

		if ( '' === $city ) {
			return array();
		}

		$points = $this->points->search( new PointQuery( city: $city, limit: self::MAX_RESULTS ) );

		return array_map(
			static fn( DeliveryPoint $point ): array => self::present( $point ),
			array_slice( $points, 0, self::MAX_RESULTS )
		);
	}

	/**
	 * Точки могло не стать между показом списка и выбором, поэтому выбор
	 * проверяется по каталогу.
	 */
	public function choose( int $delivery_point_id ): bool {
		if ( $delivery_point_id <= 0 || null === $this->points->find( $delivery_point_id ) ) {
			return false;
		}

		$this->state->choose_point( $delivery_point_id );

		return true;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function chosen(): ?array {
		$id = $this->state->chosen_point_id();

		if ( null === $id ) {
			return null;
		}

		$point = $this->points->find( $id );

		// Точку могли удалить из каталога уже после выбора.
		return null === $point ? null : self::present( $point );
	}

	public function handle_ozon_delivery_search_points(): void {
		check_ajax_referer( self::AJAX_SEARCH, 'nonce' );

		$city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';

		wp_send_json_success( array( 'points' => $this->search( (string) $city ) ) );
	}

	public function handle_ozon_delivery_choose_point(): void {
		check_ajax_referer( self::AJAX_CHOOSE, 'nonce' );

		$id = isset( $_POST['delivery_point_id'] ) ? absint( wp_unslash( $_POST['delivery_point_id'] ) ) : 0;

		if ( ! $this->choose( $id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Этот пункт выдачи больше недоступен.', 'ozon-delivery-for-woocommerce' ) )
			);
		}

		wp_send_json_success( array( 'point' => $this->chosen() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function present( DeliveryPoint $point ): array {
		return array(
			'id'                  => $point->delivery_point_id,
			'name'                => $point->name,
			'address'             => $point->full_address,
			'city'                => $point->city,
			'type'                => $point->type,
			'latitude'            => $point->latitude,
			'longitude'           => $point->longitude,
			'storage_period_days' => $point->storage_period_days,
		);
	}
}
