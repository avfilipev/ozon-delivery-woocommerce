<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Опрос статусов отправлений и отражение их в заказах.
 *
 * Вебхуков у Ozon нет, статусы приходят только опросом. Финальные статусы
 * (вручено, отменено) больше не меняются — такие отправления не опрашиваются.
 *
 * Сбой опроса не выбрасывается наружу: он не должен ломать ни админку, ни
 * расписание фоновых задач.
 */
final class StatusSync {

	public function __construct(
		private readonly Postings $postings,
		private readonly Logger $logger
	) {
	}

	public static function create(): self {
		return new self( new Postings( ClientFactory::create() ), new Logger() );
	}

	/**
	 * @return PostingInfo|null null, если опрашивать нечего или не удалось.
	 */
	public function sync_order( object $order ): ?PostingInfo {
		$posting_number = Meta::posting_number( $order );

		if ( null === $posting_number ) {
			return null;
		}

		$known = new PostingStatus( (string) $order->get_meta( Meta::POSTING_STATUS ) );

		// Финальный статус уже не изменится.
		if ( $known->is_final() ) {
			return null;
		}

		try {
			$postings = $this->postings->info( array( $posting_number ) );
		} catch ( ApiException $e ) {
			$this->logger->log(
				'warning',
				'Не удалось опросить статус отправления',
				array(
					'posting_number' => $posting_number,
					'error'          => $e->getMessage(),
				)
			);

			return null;
		}

		$info = $postings[ $posting_number ] ?? null;

		if ( null === $info ) {
			return null;
		}

		$this->apply( $order, $known, $info );

		return $info;
	}

	/**
	 * @param object[] $orders
	 *
	 * @return int Сколько заказов опрошено успешно.
	 */
	public function sync_orders( array $orders ): int {
		$synced = 0;

		foreach ( $orders as $order ) {
			if ( null !== $this->sync_order( $order ) ) {
				++$synced;
			}
		}

		return $synced;
	}

	private function apply( object $order, PostingStatus $known, PostingInfo $info ): void {
		$order->update_meta_data( Meta::POSTING_STATUS, $info->status->value );
		$order->save();

		// Опрос идёт часто, а статус меняется редко: повторять одно и то же
		// примечание незачем.
		if ( $known->value === $info->status->value ) {
			return;
		}

		$note = sprintf( 'Ozon: %s.', $info->status->label() );

		$order_status = $info->status->to_order_status();

		if ( null !== $order_status && $order_status !== $order->get_status() ) {
			$order->update_status( $order_status, $note );
		} else {
			$order->add_order_note( $note );
		}

		$this->logger->log(
			'info',
			'Статус отправления обновлён',
			array(
				'posting_number' => $info->posting_number,
				'status'         => $info->status->value,
			)
		);

		/**
		 * Статус отправления изменился.
		 *
		 * @param object      $order Заказ WooCommerce.
		 * @param PostingInfo $info  Новое состояние отправления.
		 */
		do_action( 'ozon_delivery_posting_status_changed', $order, $info );
	}
}
