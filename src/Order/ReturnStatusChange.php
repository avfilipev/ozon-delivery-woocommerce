<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Запись истории статусов возврата.
 *
 * Поля называются иначе, чем у отправлений: return_status и changed_at
 * вместо status и status_changed_at.
 */
final class ReturnStatusChange {

	public function __construct(
		public readonly ReturnStatus $status,
		public readonly ?string $changed_at = null
	) {
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function from_response( array $item ): self {
		$changed = isset( $item['changed_at'] ) ? (string) $item['changed_at'] : '';

		return new self(
			new ReturnStatus( isset( $item['return_status'] ) ? (string) $item['return_status'] : '' ),
			'' === $changed ? null : $changed
		);
	}
}
