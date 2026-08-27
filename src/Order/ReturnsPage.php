<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Страница списка возвратов с курсорной пагинацией.
 */
final class ReturnsPage {

	/**
	 * @param ReturnInfo[] $returns
	 */
	public function __construct(
		public readonly array $returns = array(),
		public readonly ?string $next_cursor = null
	) {
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public static function from_response( array $response ): self {
		$raw = isset( $response['returns'] ) && is_array( $response['returns'] ) ? $response['returns'] : array();

		$returns = array();

		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$returns[] = ReturnInfo::from_response( $item );
			}
		}

		$cursor = isset( $response['next_cursor'] ) ? (string) $response['next_cursor'] : '';

		return new self( $returns, '' === $cursor ? null : $cursor );
	}

	public function is_last(): bool {
		return null === $this->next_cursor;
	}
}
