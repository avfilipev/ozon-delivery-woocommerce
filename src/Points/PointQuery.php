<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;

/**
 * Условия выборки пунктов выдачи из локального каталога.
 *
 * Габариты и объявленная стоимость нужны, чтобы отсечь неподходящие точки
 * прямо в SQL: иначе check-availability будет вызываться впустую на точках,
 * которые заведомо не примут отправление.
 */
final class PointQuery {

	public const DEFAULT_LIMIT = 50;

	public function __construct(
		public readonly ?string $city = null,
		public readonly ?float $min_latitude = null,
		public readonly ?float $max_latitude = null,
		public readonly ?float $min_longitude = null,
		public readonly ?float $max_longitude = null,
		public readonly ?Dimensions $parcel = null,
		public readonly ?Money $declared_value = null,
		public readonly ?int $shipment_method_id = null,
		public readonly int $limit = self::DEFAULT_LIMIT
	) {
	}

	public function has_bounding_box(): bool {
		return null !== $this->min_latitude
			&& null !== $this->max_latitude
			&& null !== $this->min_longitude
			&& null !== $this->max_longitude;
	}
}
