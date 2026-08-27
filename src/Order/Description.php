<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Содержимое отправления свободным текстом.
 *
 * `description` — обязательное поле order/create, и это единственное, что
 * Ozon узнаёт о содержимом: ни SKU, ни артикулов в схеме нет.
 *
 * По умолчанию туда идёт только номер заказа. Названия товаров намеренно не
 * подставляются: описание видят и покупатель, и логистика, а магазину может
 * быть важно не раскрывать состав заказа. Кому нужно иначе — добавит
 * фильтром ozon_delivery_posting_description.
 */
final class Description {

	public const MAX_LENGTH = 255;

	public static function for_order( object $order ): string {
		$number = is_callable( array( $order, 'get_order_number' ) )
			? (string) $order->get_order_number()
			: '';

		$description = '' === trim( $number )
			? 'Заказ интернет-магазина'
			: sprintf( 'Заказ №%s', $number );

		/**
		 * Описание содержимого отправления.
		 *
		 * @param string $description Описание по умолчанию.
		 * @param object $order       Заказ WooCommerce.
		 */
		$filtered = apply_filters( 'ozon_delivery_posting_description', $description, $order );

		$filtered = is_string( $filtered ) && '' !== trim( $filtered ) ? trim( $filtered ) : $description;

		return mb_substr( $filtered, 0, self::MAX_LENGTH );
	}
}
