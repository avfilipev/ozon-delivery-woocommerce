<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Api\ErrorCodes;

/**
 * Предварительный срок доставки по одному методу из delivery/location.
 *
 * @see docs/API.md, метод delivery/location
 */
final class DeliveryEstimate {

	public function __construct(
		public readonly int $shipment_method_id,
		public readonly bool $available,
		public readonly ?int $estimated_delivery_days = null,
		public readonly ?string $cutoff_at = null,
		public readonly string $error_code = '',
		public readonly string $message = ''
	) {
	}

	/**
	 * @param array<string, mixed> $result Элемент results[] из ответа Ozon.
	 */
	public static function from_result( array $result ): self {
		$method_id = (int) ( $result['shipment_method_id'] ?? 0 );
		$cutoff    = isset( $result['cutoff_at'] ) ? (string) $result['cutoff_at'] : '';
		$cutoff    = '' === $cutoff ? null : $cutoff;

		$days = isset( $result['estimated_delivery_days'] ) && is_numeric( $result['estimated_delivery_days'] )
			? (int) $result['estimated_delivery_days']
			: null;

		$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();

		if ( array() === $error ) {
			return new self( $method_id, true, $days, $cutoff );
		}

		$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';

		return new self(
			$method_id,
			false,
			$days,
			$cutoff,
			$code,
			ErrorCodes::message( $code, $message )
		);
	}
}
