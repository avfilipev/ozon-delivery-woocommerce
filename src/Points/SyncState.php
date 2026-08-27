<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

/**
 * Состояние обхода каталога ПВЗ.
 *
 * Хранится в опции, поэтому обрыв на середине не теряет прогресс:
 * следующий шаг продолжит с сохранённого курсора.
 */
final class SyncState {

	public function __construct(
		public readonly ?string $cursor = null,
		public readonly bool $finished = false,
		public readonly int $processed = 0,
		public readonly string $started_at = ''
	) {
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function from_array( array $state ): self {
		$cursor = isset( $state['cursor'] ) ? (string) $state['cursor'] : '';

		return new self(
			'' === $cursor ? null : $cursor,
			! empty( $state['finished'] ),
			isset( $state['processed'] ) ? (int) $state['processed'] : 0,
			isset( $state['started_at'] ) ? (string) $state['started_at'] : ''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'cursor'     => $this->cursor,
			'finished'   => $this->finished,
			'processed'  => $this->processed,
			'started_at' => $this->started_at,
		);
	}

	public function is_fresh(): bool {
		return null === $this->cursor && 0 === $this->processed && ! $this->finished;
	}
}
