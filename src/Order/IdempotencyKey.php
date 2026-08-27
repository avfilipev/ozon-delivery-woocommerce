<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Ключ идемпотентности для order/create.
 *
 * Правило 4: order/create вызывается только с заголовком Idempotency-Key.
 * Ключ обязан переживать повторные попытки — он лежит в мете заказа, а не в
 * памяти процесса. Иначе повтор после таймаута создаст в Ozon второе
 * отправление: повторный запрос с тем же ключом возвращает исходный ответ,
 * с новым — создаёт новый заказ.
 */
final class IdempotencyKey {

	public static function for_order( object $order ): string {
		$existing = (string) $order->get_meta( Meta::IDEMPOTENCY_KEY );

		if ( '' !== $existing ) {
			return $existing;
		}

		$key = wp_generate_uuid4();

		$order->update_meta_data( Meta::IDEMPOTENCY_KEY, $key );
		$order->save();

		return $key;
	}

	/**
	 * Сбрасывает ключ: нужно, когда заказ осознанно передают заново как новый,
	 * а не повторяют неудавшуюся попытку.
	 */
	public static function reset( object $order ): void {
		$order->update_meta_data( Meta::IDEMPOTENCY_KEY, '' );
		$order->save();
	}
}
