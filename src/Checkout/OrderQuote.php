<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\QuoteBuilder;

/**
 * Разбивка расчёта в заказе: стоимость доставки, страховка, срок.
 *
 * Метабокс заказа показывает эти три значения, но записывать их было некому:
 * `Meta::save_quote()` существовал и был покрыт тестами, а в рабочем коде не
 * вызывался ниоткуда — три строки в админке оставались пустыми всегда.
 *
 * Сетевого запроса здесь обычно нет: тот же расчёт только что сделал метод
 * доставки, а `RateCalculator` держит его в кэше по тем же параметрам.
 *
 * Класс намеренно не final: он подменяется в тесте, который следит, что
 * запись расчёта вообще вызывается из оформления заказа.
 */
class OrderQuote {

	public function __construct(
		private readonly CustomerPhone $phone = new CustomerPhone(),
		private readonly CartPackage $package = new CartPackage()
	) {
	}

	public function save( object $order, int $point_id ): void {
		$package = $this->package->first();

		if ( null === $package ) {
			return;
		}

		Meta::save_quote(
			$order,
			QuoteBuilder::create()->quote( $package, $this->phone->resolve(), Destination::point( $point_id ) )
		);
	}
}
