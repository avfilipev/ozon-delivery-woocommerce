<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

use Spoki\OzonDelivery\Points\CatalogSync;
use Spoki\OzonDelivery\Points\Repository;

/**
 * Состояние локального каталога ПВЗ для админки: сколько точек, когда
 * обновлялись, идёт ли обход прямо сейчас.
 */
final class CatalogStatus {

	public function __construct(
		private readonly Repository $repository,
		private readonly CatalogSync $sync
	) {
	}

	/**
	 * @return array{total: int, active: int, last_synced_at: string|null, running: bool, processed: int}
	 */
	public function summary(): array {
		$state = $this->sync->state();

		return array(
			'total'          => $this->repository->count(),
			'active'         => $this->repository->count_active(),
			'last_synced_at' => $this->repository->last_synced_at(),
			'running'        => $this->sync->is_running(),
			'processed'      => $state->processed,
		);
	}

	/**
	 * Одна фраза для экрана настроек.
	 */
	public function describe(): string {
		$summary = $this->summary();

		if ( $summary['running'] ) {
			return sprintf(
				'Идёт обновление каталога: обработано точек — %d.',
				$summary['processed']
			);
		}

		if ( 0 === $summary['total'] ) {
			return 'Каталог пунктов выдачи не загружен. Запустите синхронизацию.';
		}

		$description = sprintf(
			'В каталоге точек: %d, из них доступно: %d.',
			$summary['total'],
			$summary['active']
		);

		if ( null !== $summary['last_synced_at'] ) {
			$description .= sprintf( ' Последнее обновление: %s (UTC).', $summary['last_synced_at'] );
		}

		return $description;
	}
}
