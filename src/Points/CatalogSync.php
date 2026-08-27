<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Api\Endpoints\DeliveryPoints;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Обход каталога ПВЗ и запись его в локальную таблицу.
 *
 * Каталог берётся страницами: delivery-point/list отдаёт только
 * идентификаторы и курсор, детали добираются пачками через
 * delivery-point/info. Состояние обхода лежит в опции, поэтому шаг можно
 * запускать по расписанию, а обрыв на середине не начинает всё заново.
 *
 * @see docs/API.md, раздел «Пункты выдачи»
 */
final class CatalogSync {

	public const STATE_OPTION = 'ozon_delivery_points_sync_state';

	public function __construct(
		private readonly DeliveryPoints $endpoint,
		private readonly Repository $repository,
		private readonly Logger $logger
	) {
	}

	/**
	 * Начинает обход заново, сбрасывая курсор и счётчик.
	 */
	public function start(): SyncState {
		$state = new SyncState( null, false, 0, current_time( 'mysql', true ) );

		$this->save_state( $state );

		return $state;
	}

	public function state(): SyncState {
		$stored = get_option( self::STATE_OPTION, array() );

		return SyncState::from_array( is_array( $stored ) ? $stored : array() );
	}

	public function is_running(): bool {
		$state = $this->state();

		return ! $state->finished && ! $state->is_fresh();
	}

	/**
	 * Один шаг обхода: страница каталога, детали пачками, запись в таблицу.
	 *
	 * Исключения наружу не глушатся — вызывающий планировщик решает, повторять
	 * ли шаг. Курсор при этом остаётся прежним, поэтому повтор продолжит с того
	 * же места.
	 */
	public function run_step( int $page_size = DeliveryPoints::DEFAULT_PAGE_SIZE ): SyncState {
		$state = $this->state();

		if ( $state->finished ) {
			return $state;
		}

		if ( '' === $state->started_at ) {
			$state = $this->start();
		}

		$page = $this->endpoint->list_page( $state->cursor, $page_size );

		$processed = $state->processed + $this->store( $page );

		if ( $page->is_last() ) {
			return $this->finish( $state, $processed );
		}

		$next = new SyncState( $page->next_cursor, false, $processed, $state->started_at );

		$this->save_state( $next );

		return $next;
	}

	/**
	 * Детали точек добираются пачками: delivery-point/info принимает массив
	 * идентификаторов, но тащить туда всю страницу целиком не стоит.
	 *
	 * @return int Сколько точек записано.
	 */
	private function store( CatalogPage $page ): int {
		if ( $page->is_empty() ) {
			return 0;
		}

		$methods_by_id = array();

		foreach ( $page->ids() as $id ) {
			$methods_by_id[ $id ] = $page->shipment_method_ids_for( $id );
		}

		$saved = 0;

		foreach ( array_chunk( $page->ids(), DeliveryPoints::INFO_BATCH_SIZE ) as $batch ) {
			$saved += $this->repository->save_many( $this->endpoint->info( $batch, $methods_by_id ) );
		}

		return $saved;
	}

	/**
	 * Точки, не встреченные в этом обходе, удаляются только здесь: снести их
	 * на середине означало бы выкосить половину каталога из-за одного обрыва.
	 */
	private function finish( SyncState $state, int $processed ): SyncState {
		$removed = $this->repository->delete_stale( $state->started_at );

		$finished = new SyncState( null, true, $processed, $state->started_at );

		$this->save_state( $finished );

		$this->logger->log(
			'info',
			'Каталог пунктов выдачи обновлён',
			array(
				'processed' => $processed,
				'removed'   => $removed,
			)
		);

		/**
		 * Каталог ПВЗ полностью обновлён.
		 *
		 * @param int $processed Сколько точек записано.
		 * @param int $removed   Сколько устаревших удалено.
		 */
		do_action( 'ozon_delivery_points_synced', $processed, $removed );

		return $finished;
	}

	private function save_state( SyncState $state ): void {
		update_option( self::STATE_OPTION, $state->to_array() );
	}
}
