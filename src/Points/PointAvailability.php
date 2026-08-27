<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Points;

use Spoki\OzonDelivery\Api\ErrorCodes;

/**
 * Итог check-availability по одному пункту выдачи.
 *
 * @see docs/API.md, метод delivery-point/check-availability
 */
final class PointAvailability {

	public function __construct(
		public readonly int $delivery_point_id,
		public readonly bool $available,
		public readonly string $error_code = '',
		public readonly string $message = '',
		public readonly ?string $cutoff_at = null
	) {
	}

	/**
	 * @param array<string, mixed> $result Элемент results[] из ответа Ozon.
	 */
	public static function from_result( array $result ): self {
		$point_id = (int) ( $result['delivery_point_id'] ?? 0 );
		$error    = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();

		$cutoff = isset( $result['cutoff_at'] ) ? (string) $result['cutoff_at'] : '';

		if ( array() === $error ) {
			return new self( $point_id, true, '', '', '' === $cutoff ? null : $cutoff );
		}

		$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';

		return new self(
			$point_id,
			false,
			$code,
			ErrorCodes::message( $code, $message ),
			'' === $cutoff ? null : $cutoff
		);
	}
}
