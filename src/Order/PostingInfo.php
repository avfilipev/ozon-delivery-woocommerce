<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Состояние отправления из posting/info.
 */
final class PostingInfo {

	public function __construct(
		public readonly string $posting_number,
		public readonly PostingStatus $status,
		public readonly string $order_number = '',
		public readonly ?string $status_changed_at = null,
		public readonly ?string $delivery_at = null
	) {
	}

	/**
	 * @param array<string, mixed> $item Элемент postings[] из ответа Ozon.
	 */
	public static function from_response( array $item ): self {
		$changed = isset( $item['status_changed_at'] ) ? (string) $item['status_changed_at'] : '';
		$at      = isset( $item['delivery_at'] ) ? (string) $item['delivery_at'] : '';

		return new self(
			isset( $item['posting_number'] ) ? (string) $item['posting_number'] : '',
			new PostingStatus( isset( $item['status'] ) ? (string) $item['status'] : '' ),
			isset( $item['order_number'] ) ? (string) $item['order_number'] : '',
			'' === $changed ? null : $changed,
			'' === $at ? null : $at
		);
	}
}
