<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Одна запись истории статусов отправления.
 */
final class StatusChange {

	public function __construct(
		public readonly PostingStatus $status,
		public readonly ?string $changed_at = null
	) {
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function from_response( array $item ): self {
		$changed = isset( $item['status_changed_at'] ) ? (string) $item['status_changed_at'] : '';

		return new self(
			new PostingStatus( isset( $item['status'] ) ? (string) $item['status'] : '' ),
			'' === $changed ? null : $changed
		);
	}
}
