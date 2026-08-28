<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Support\Money;

/**
 * Подбор пунктов выдачи под конкретное отправление.
 *
 * Два шага. Сначала локальный каталог отсекает точки, которые заведомо не
 * подходят по ограничениям — иначе check-availability вызывался бы впустую.
 * Затем оставшиеся подтверждаются у Ozon: последнее слово всегда за ним.
 *
 * Исключения наружу не глушатся. Что показать покупателю, когда API молчит, —
 * решение слоя чекаута: по правилу 5 оно не должно валить оформление заказа.
 *
 * НЕ ПОДКЛЮЧЁН К ЧЕКАУТУ, И ЭТО НАМЕРЕННО. Выбор точки проверяет сам расчёт:
 * order/checkout идёт сразу за выбором и отвечает то же самое, а отказ по
 * конкретной точке покупатель уже видит подсказкой «выберите другой пункт»
 * (CheckoutQuote::customer_message()). Звать check-availability отдельно —
 * второй запрос к Ozon за тем же ответом.
 *
 * Класс нужен там, где расчёта ещё нет: карта ПВЗ должна показывать сразу
 * подтверждённые точки, не дожидаясь выбора. Это фаза 7.
 */
final class Availability {

	public function __construct(
		private readonly Repository $repository,
		private readonly DeliveryPoints $endpoint,
		private readonly Logger $logger
	) {
	}

	/**
	 * Точки, подтверждённые Ozon для этого отправления.
	 *
	 * @return DeliveryPoint[]
	 */
	public function find(
		PointQuery $query,
		int $shipment_method_id,
		Dimensions $parcel,
		Money $declared_value,
		?string $cutoff_at = null
	): array {
		$candidates = $this->repository->search( $query );

		if ( array() === $candidates ) {
			return array();
		}

		$results = $this->endpoint->check_availability(
			array_map( static fn( DeliveryPoint $point ): int => $point->delivery_point_id, $candidates ),
			$shipment_method_id,
			$parcel,
			$declared_value,
			$cutoff_at
		);

		$confirmed = array();

		foreach ( $candidates as $point ) {
			$result = $results[ $point->delivery_point_id ] ?? null;

			// Точку, о которой Ozon ничего не сказал, показывать нельзя:
			// молчание — не подтверждение.
			if ( null === $result || ! $result->available ) {
				continue;
			}

			$confirmed[] = $point;
		}

		$this->logger->log(
			'debug',
			'Подбор пунктов выдачи',
			array(
				'candidates' => count( $candidates ),
				'confirmed'  => count( $confirmed ),
			)
		);

		/**
		 * Итоговый список пунктов выдачи перед показом покупателю.
		 *
		 * @param DeliveryPoint[] $confirmed  Подтверждённые точки.
		 * @param DeliveryPoint[] $candidates Что отдал локальный каталог.
		 */
		return (array) apply_filters( 'ozon_delivery_available_points', $confirmed, $candidates );
	}

	/**
	 * Подтвердить одну точку: нужно при валидации выбора на чекауте.
	 */
	public function is_available(
		int $delivery_point_id,
		int $shipment_method_id,
		Dimensions $parcel,
		Money $declared_value,
		?string $cutoff_at = null
	): bool {
		return null === $this->rejection_reason(
			$delivery_point_id,
			$shipment_method_id,
			$parcel,
			$declared_value,
			$cutoff_at
		);
	}

	/**
	 * @return string|null Причина отказа, null — точка подходит.
	 */
	public function rejection_reason(
		int $delivery_point_id,
		int $shipment_method_id,
		Dimensions $parcel,
		Money $declared_value,
		?string $cutoff_at = null
	): ?string {
		$results = $this->endpoint->check_availability(
			array( $delivery_point_id ),
			$shipment_method_id,
			$parcel,
			$declared_value,
			$cutoff_at
		);

		$result = $results[ $delivery_point_id ] ?? null;

		if ( null === $result ) {
			return 'Ozon не ответил по этому пункту выдачи.';
		}

		return $result->available ? null : $result->message;
	}
}
