<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Support\Money;

/**
 * Отпечаток отправления: по нему кэшируется расчёт доставки.
 *
 * WooCommerce пересчитывает доставку на каждое изменение корзины и на каждую
 * загрузку чекаута. Без кэша это был бы запрос к Ozon каждый раз.
 *
 * В отпечаток входит всё, что влияет на цену. Телефон покупателя участвует в
 * расчёте, но в ключ попадает только его хеш: персональные данные в имени
 * транзиента хранить нельзя.
 */
final class PackageSignature {

	public static function create(
		string $phone_number,
		int $shipment_method_id,
		Dimensions $dimensions,
		Money $declared_value,
		Destination $destination,
		?string $cutoff_at = null
	): string {
		$parts = array(
			'phone'  => trim( $phone_number ),
			'method' => $shipment_method_id,
			'parcel' => $dimensions->to_array(),
			'value'  => $declared_value->to_array(),
			'to'     => $destination->to_array(),
			'cutoff' => $cutoff_at ?? '',
		);

		return substr( md5( (string) wp_json_encode( $parts ) ), 0, 32 );
	}
}
