<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Points\PointQuery;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Shipping\QuoteBuilder;

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
		private readonly SessionState $state = new SessionState(),
		private readonly CartPackage $package = new CartPackage()
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

		$points = $this->points->search( $this->query( $city ) );

		return array_map(
			static fn( DeliveryPoint $point ): array => self::present( $point ),
			array_slice( $points, 0, self::MAX_RESULTS )
		);
	}

	/**
	 * Почему список пуст, если дело не в городе.
	 *
	 * Отсев по методу отгрузки и габаритам делает пустой список гораздо более
	 * вероятным. Без объяснения покупатель с крупной посылкой видит «в этом
	 * городе пунктов не нашлось» и идёт искать опечатку в названии города,
	 * хотя город тут ни при чём.
	 *
	 * @return string Пустая строка — обычный случай «в городе ничего нет».
	 */
	public function explain_empty( string $city ): string {
		$city = trim( $city );

		if ( '' === $city ) {
			return '';
		}

		$without_filters = $this->points->search( new PointQuery( city: $city, limit: 1 ) );

		if ( array() === $without_filters ) {
			return '';
		}

		return __(
			'В этом городе есть пункты выдачи, но ни один не принимает такую посылку. Попробуйте разделить заказ.',
			'ozon-delivery-for-woocommerce'
		);
	}

	/**
	 * Условия выборки: город плюс всё, чем точку можно отсечь заранее.
	 *
	 * Каталог умеет отбрасывать неподходящие точки прямо в SQL, но поиск
	 * этим не пользовался — покупателю показывались точки, которые не
	 * поддерживают наш метод отгрузки или не примут эту посылку. Выбрав
	 * такую, он получал пустую строку доставки и невнятное объяснение.
	 *
	 * Габариты и стоимость берутся из корзины, если она есть: в поиске из
	 * админки или WP-CLI её нет, и тогда отсев идёт только по методу.
	 */
	private function query( string $city ): PointQuery {
		$method_id = (int) get_option( Settings::FIELD_SHIPMENT_METHOD_ID, '0' );
		$package   = $this->package->first();

		if ( null === $package ) {
			return new PointQuery(
				city: $city,
				shipment_method_id: $method_id > 0 ? $method_id : null,
				limit: self::MAX_RESULTS
			);
		}

		$builder = QuoteBuilder::create();

		return new PointQuery(
			city: $city,
			parcel: $builder->parcel( $package ),
			declared_value: $builder->declared_value( $package ),
			shipment_method_id: $method_id > 0 ? $method_id : null,
			limit: self::MAX_RESULTS
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

		$points = $this->search( (string) $city );

		wp_send_json_success(
			array(
				'points'  => $points,
				'message' => array() === $points ? $this->explain_empty( (string) $city ) : '',
			)
		);
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
