<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Support\Money;

/**
 * Расчёт стоимости доставки через order/checkout, с кэшем.
 *
 * Наружу исключения не выходят: по правилу 5 ошибка расчёта не должна валить
 * оформление заказа. Вместо этого возвращается непригодный расчёт с
 * причиной — метод доставки просто не покажется, а причина уйдёт в лог и в
 * таблицу заказа.
 */
final class RateCalculator {

	private const CACHE_PREFIX = 'ozon_delivery_rate_';

	/**
	 * Успешный расчёт живёт достаточно долго, чтобы пережить обычное
	 * блуждание покупателя по чекауту.
	 */
	public const SUCCESS_TTL = 900;

	/**
	 * Неудачу тоже надо кэшировать, иначе каждое обновление корзины будет
	 * долбить лежащий API. Но ненадолго: восстановление должно замечаться
	 * быстро.
	 */
	public const FAILURE_TTL = 120;

	public function __construct(
		private readonly Orders $orders,
		private readonly Logger $logger
	) {
	}

	public function quote(
		string $phone_number,
		int $shipment_method_id,
		Dimensions $dimensions,
		Money $declared_value,
		Destination $destination,
		?string $cutoff_at = null
	): CheckoutQuote {
		$key = self::CACHE_PREFIX . PackageSignature::create(
			$phone_number,
			$shipment_method_id,
			$dimensions,
			$declared_value,
			$destination,
			$cutoff_at
		);

		$cached = get_transient( $key );

		if ( $cached instanceof CheckoutQuote ) {
			return $cached;
		}

		$quote = $this->fetch(
			$phone_number,
			$shipment_method_id,
			$dimensions,
			$declared_value,
			$destination,
			$cutoff_at
		);

		set_transient( $key, $quote, $quote->available ? self::SUCCESS_TTL : self::FAILURE_TTL );

		return $quote;
	}

	private function fetch(
		string $phone_number,
		int $shipment_method_id,
		Dimensions $dimensions,
		Money $declared_value,
		Destination $destination,
		?string $cutoff_at
	): CheckoutQuote {
		try {
			return $this->orders->checkout(
				$phone_number,
				$shipment_method_id,
				$dimensions,
				$declared_value,
				$destination,
				$cutoff_at
			);
		} catch ( ApiException $e ) {
			$this->logger->log(
				'warning',
				'Не удалось рассчитать доставку Ozon',
				array( 'error' => $e->getMessage() )
			);

			return CheckoutQuote::failed( '', 'Не удалось рассчитать доставку Ozon. Попробуйте позже.' );
		}
	}
}
