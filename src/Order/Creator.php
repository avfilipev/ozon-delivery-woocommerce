<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\QuoteBuilder;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Передача заказа WooCommerce в Ozon.
 *
 * Габариты и объявленная стоимость считаются тем же кодом, что показывал
 * цену покупателю: заказ переводится в формат пакета доставки и проходит
 * через тот же упаковщик.
 *
 * Исключения наружу не выходят — результат всегда возвращается объектом,
 * а причина отказа попадает в мету заказа и в примечание. Иначе кнопка в
 * админке роняла бы страницу, а фоновая задача теряла бы расписание.
 */
final class Creator {

	public function __construct(
		private readonly Orders $orders,
		private readonly QuoteBuilder $builder,
		private readonly Logger $logger
	) {
	}

	public static function create(): self {
		return new self(
			new Orders( ClientFactory::create() ),
			QuoteBuilder::create(),
			new Logger()
		);
	}

	public function push( object $order ): CreatedOrder {
		$already = Meta::order_number( $order );

		// Уже переданный заказ повторно не отправляем: защита от второго
		// отправления ещё до Idempotency-Key.
		if ( null !== $already ) {
			$posting_number = Meta::posting_number( $order );

			return new CreatedOrder(
				$already,
				null === $posting_number ? array() : array( new CreatedPosting( 1, $posting_number ) )
			);
		}

		$point_id = Meta::point_id( $order );

		if ( null === $point_id ) {
			return $this->fail( $order, 'В заказе не выбран пункт выдачи Ozon.' );
		}

		$method_id = $this->builder->shipment_method_id();

		if ( $method_id <= 0 ) {
			return $this->fail( $order, 'Не задан Shipment Method ID в настройках Ozon Доставки.' );
		}

		$package = OrderPackage::from_order( $order );

		try {
			$created = $this->orders->create(
				(string) $order->get_order_number(),
				(string) $order->get_billing_phone(),
				(string) $order->get_formatted_billing_full_name(),
				$method_id,
				Description::for_order( $order ),
				$this->builder->parcel( $package ),
				$this->builder->declared_value( $package ),
				Destination::point( $point_id ),
				IdempotencyKey::for_order( $order )
			);
		} catch ( DryRunException $e ) {
			return $this->fail( $order, $e->getMessage(), 'info' );
		} catch ( ApiException $e ) {
			return $this->fail( $order, $e->getMessage() );
		}

		if ( ! $created->succeeded() ) {
			return $this->fail( $order, $created->error_message() );
		}

		$this->succeed( $order, $created );

		return $created;
	}

	private function succeed( object $order, CreatedOrder $created ): void {
		Meta::save_ozon_order( $order, $created->order_number, (string) $created->first_posting_number() );

		$order->delete_meta_data( Meta::ERROR );
		$order->save();

		$note = sprintf(
			'Заказ передан в Ozon. Номер заказа: %s, отправление: %s.',
			$created->order_number,
			(string) $created->first_posting_number()
		);

		$order->add_order_note( $note );

		$this->logger->log( 'info', 'Заказ передан в Ozon', array( 'order_number' => $created->order_number ) );

		/**
		 * Заказ успешно передан в Ozon.
		 *
		 * @param object       $order   Заказ WooCommerce.
		 * @param CreatedOrder $created Ответ Ozon.
		 */
		do_action( 'ozon_delivery_order_created', $order, $created );
	}

	private function fail( object $order, string $message, string $level = 'warning' ): CreatedOrder {
		$order->update_meta_data( Meta::ERROR, $message );
		$order->save();

		$order->add_order_note( sprintf( 'Ozon Доставка: %s', $message ) );

		$this->logger->log( $level, 'Заказ не передан в Ozon', array( 'reason' => $message ) );

		return new CreatedOrder( '', array(), $message );
	}
}
