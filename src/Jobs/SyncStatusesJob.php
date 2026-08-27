<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Jobs;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Order\StatusSync;
use Spoki\OzonDelivery\Support\Logger;
use Throwable;

/**
 * Опрос статусов отправлений по расписанию.
 *
 * Вебхуков у Ozon нет — статусы приходят только опросом. Берутся только
 * заказы с отправлением, статус которого ещё не финальный: вручённые и
 * отменённые опрашивать бессмысленно.
 */
final class SyncStatusesJob {

	public const HOOK = 'ozon_delivery_sync_statuses';

	public const GROUP = 'ozon-delivery';

	/**
	 * Сколько заказов опрашивать за один проход.
	 */
	private const BATCH = 20;

	public function __construct(
		private readonly StatusSync $sync,
		private readonly Logger $logger
	) {
	}

	public static function create(): self {
		$logger = new Logger();

		return new self( new StatusSync( new Postings( ClientFactory::create() ), $logger ), $logger );
	}

	public static function schedule_hourly(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}

		/**
		 * Как часто опрашивать статусы отправлений.
		 *
		 * @param int $interval Интервал в секундах.
		 */
		$interval = (int) apply_filters( 'ozon_delivery_status_sync_interval', HOUR_IN_SECONDS );

		as_schedule_recurring_action( time() + $interval, $interval, self::HOOK, array(), self::GROUP );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Исключения ловятся: выпущенные наружу, они пометили бы задачу
	 * Action Scheduler упавшей и оборвали расписание.
	 */
	public function run(): void {
		try {
			$orders = $this->pending_orders();

			if ( array() === $orders ) {
				return;
			}

			$this->sync->sync_orders( $orders );
		} catch ( Throwable $e ) {
			$this->logger->log(
				'error',
				'Опрос статусов остановлен из-за ошибки',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Заказы с отправлением, статус которого ещё не финальный.
	 *
	 * @return object[]
	 */
	private function pending_orders(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'orderby'    => 'modified',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Meta::POSTING_NUMBER,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Meta::POSTING_STATUS,
						'value'   => array( 'DELIVERED', 'CANCELED' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		return is_array( $orders ) ? $orders : array();
	}
}
