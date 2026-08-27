<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Jobs;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;
use Spoki\OzonDelivery\Support\Logger;
use Throwable;

/**
 * Фоновый обход каталога ПВЗ через Action Scheduler.
 *
 * Каждая задача делает ровно один шаг и ставит следующую, пока каталог не
 * закончится. Так обход не упирается ни в лимит времени выполнения, ни в
 * память, а обрыв продолжается с сохранённого курсора.
 *
 * Action Scheduler приезжает вместе с WooCommerce; если его нет, планировать
 * нечего — синхронизацию можно запустить вручную из админки или WP-CLI.
 */
final class SyncPointsJob {

	public const HOOK = 'ozon_delivery_sync_points';

	public const GROUP = 'ozon-delivery';

	/**
	 * Пауза перед повтором шага, упавшего с ошибкой API.
	 */
	private const RETRY_DELAY = 300;

	public function __construct(
		private readonly CatalogSync $sync,
		private readonly Logger $logger
	) {
	}

	/**
	 * Собирает задачу со всеми зависимостями. Вызывается лениво, уже в момент
	 * выполнения: строить клиента и читать настройки на каждом запросе
	 * WordPress незачем.
	 */
	public static function create(): self {
		$logger = new Logger();

		return new self(
			new CatalogSync(
				new DeliveryPoints( ClientFactory::create() ),
				new Repository(),
				$logger
			),
			$logger
		);
	}

	/**
	 * Начинает обход заново прямо сейчас.
	 */
	public function start_now(): void {
		$this->sync->start();

		self::schedule_step();
	}

	/**
	 * Ставит ежедневное обновление каталога, если его ещё нет.
	 */
	public static function schedule_daily(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}

		/**
		 * Как часто обновлять каталог пунктов выдачи.
		 *
		 * @param int $interval Интервал в секундах.
		 */
		$interval = (int) apply_filters( 'ozon_delivery_points_sync_interval', DAY_IN_SECONDS );

		as_schedule_recurring_action( time() + $interval, $interval, self::HOOK, array(), self::GROUP );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Один шаг обхода.
	 *
	 * Исключения ловятся намеренно: если выпустить их в Action Scheduler, тот
	 * отметит задачу упавшей и расписание оборвётся. Вместо этого шаг ставится
	 * заново — курсор сохранён, обход продолжится с того же места.
	 */
	public function run(): void {
		try {
			$state = $this->sync->run_step();
		} catch ( ApiException $e ) {
			$this->logger->log(
				'warning',
				'Шаг синхронизации каталога не удался, повтор отложен',
				array( 'error' => $e->getMessage() )
			);

			self::schedule_step( self::RETRY_DELAY );

			return;
		} catch ( Throwable $e ) {
			$this->logger->log(
				'error',
				'Синхронизация каталога остановлена из-за ошибки',
				array( 'error' => $e->getMessage() )
			);

			return;
		}

		if ( ! $state->finished ) {
			self::schedule_step();
		}
	}

	private static function schedule_step( int $delay = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( time() + $delay, self::HOOK, array(), self::GROUP );
	}
}
