<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Points\DeliveryPoint;
use Spoki\OzonDelivery\Shipping\CheckoutQuote;

/**
 * Мета заказа: выбранный пункт выдачи, расчёт и идентификаторы Ozon.
 *
 * Ключи начинаются с подчёркивания — такая мета не показывается в списке
 * произвольных полей. Суммы хранятся строками: правило 9, никакой
 * float-арифметики.
 *
 * Работает через методы CRUD-объекта заказа, а не через update_post_meta,
 * поэтому одинаково живёт и с HPOS, и со старым хранилищем.
 */
final class Meta {

	public const POINT_ID = '_ozon_delivery_point_id';

	public const POINT_NAME = '_ozon_delivery_point_name';

	public const POINT_ADDRESS = '_ozon_delivery_point_address';

	public const DELIVERY_COST = '_ozon_delivery_cost';

	public const INSURANCE_COST = '_ozon_delivery_insurance_cost';

	public const DELIVERY_DAYS = '_ozon_delivery_days';

	public const CUTOFF_AT = '_ozon_delivery_cutoff_at';

	public const ORDER_NUMBER = '_ozon_delivery_order_number';

	public const POSTING_NUMBER = '_ozon_delivery_posting_number';

	public const ERROR = '_ozon_delivery_error';

	public const IDEMPOTENCY_KEY = '_ozon_delivery_idempotency_key';

	public static function save_point( object $order, DeliveryPoint $point ): void {
		$order->update_meta_data( self::POINT_ID, $point->delivery_point_id );
		$order->update_meta_data( self::POINT_NAME, $point->name );
		$order->update_meta_data( self::POINT_ADDRESS, $point->full_address );

		$order->save();
	}

	public static function point_id( object $order ): ?int {
		$value = (int) $order->get_meta( self::POINT_ID );

		return $value > 0 ? $value : null;
	}

	/**
	 * Записывает расчёт. Неудачный расчёт становится строкой ошибки в заказе:
	 * правило 5 запрещает выбрасывать её через wc_add_notice('error').
	 */
	public static function save_quote( object $order, CheckoutQuote $quote ): void {
		if ( ! $quote->available ) {
			$order->update_meta_data( self::ERROR, $quote->message );
			$order->save();

			return;
		}

		$order->delete_meta_data( self::ERROR );

		if ( null !== $quote->delivery_cost ) {
			$order->update_meta_data( self::DELIVERY_COST, $quote->delivery_cost->amount );
		}

		if ( null !== $quote->insurance_cost ) {
			$order->update_meta_data( self::INSURANCE_COST, $quote->insurance_cost->amount );
		}

		if ( null !== $quote->estimated_delivery_days ) {
			$order->update_meta_data( self::DELIVERY_DAYS, $quote->estimated_delivery_days );
		}

		if ( null !== $quote->cutoff_at ) {
			$order->update_meta_data( self::CUTOFF_AT, $quote->cutoff_at );
		}

		$order->save();
	}

	public static function save_ozon_order( object $order, string $order_number, string $posting_number ): void {
		$order->update_meta_data( self::ORDER_NUMBER, $order_number );
		$order->update_meta_data( self::POSTING_NUMBER, $posting_number );

		$order->save();
	}

	public static function order_number( object $order ): ?string {
		return self::non_empty( $order, self::ORDER_NUMBER );
	}

	public static function posting_number( object $order ): ?string {
		return self::non_empty( $order, self::POSTING_NUMBER );
	}

	public static function error( object $order ): ?string {
		return self::non_empty( $order, self::ERROR );
	}

	private static function non_empty( object $order, string $key ): ?string {
		$value = (string) $order->get_meta( $key );

		return '' === $value ? null : $value;
	}
}
