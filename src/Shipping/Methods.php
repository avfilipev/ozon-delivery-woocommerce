<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

/**
 * Идентификаторы методов доставки плагина.
 *
 * Держатся отдельно от самих классов методов намеренно: MethodPickup
 * наследует WC_Shipping_Method, поэтому его нельзя даже автозагрузить без
 * работающего WooCommerce. Обращение к константе на нём из юнит-теста роняет
 * PHP молча, без сообщения.
 */
final class Methods {

	public const PICKUP = 'ozon_delivery_pickup';
}
