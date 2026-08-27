<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Api\ErrorCodes;

/**
 * Одно отправление в ответе order/create.
 */
final class CreatedPosting {

	public function __construct(
		public readonly int $request_id,
		public readonly string $posting_number = '',
		public readonly string $posting_external_id = '',
		public readonly string $error_code = '',
		public readonly string $message = ''
	) {
	}

	/**
	 * @param array<string, mixed> $item Элемент postings[] или results[].
	 */
	public static function from_response( array $item ): self {
		$request_id = (int) ( $item['request_id'] ?? 0 );
		$error      = isset( $item['error'] ) && is_array( $item['error'] ) ? $item['error'] : array();

		if ( array() !== $error ) {
			$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
			$message = isset( $error['message'] ) ? (string) $error['message'] : '';

			return new self( $request_id, '', '', $code, ErrorCodes::message( $code, $message ) );
		}

		return new self(
			$request_id,
			isset( $item['posting_number'] ) ? (string) $item['posting_number'] : '',
			isset( $item['posting_external_id'] ) ? (string) $item['posting_external_id'] : ''
		);
	}

	public function succeeded(): bool {
		return '' === $this->error_code && '' !== $this->posting_number;
	}
}
