<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Возврат или отмена, как их отдаёт return/search и return/info.
 *
 * @see docs/API.md, раздел «Возвраты»
 */
final class ReturnInfo {

	public function __construct(
		public readonly string $return_number,
		public readonly ReturnStatus $status,
		public readonly string $return_external_id = '',
		public readonly string $barcode = '',
		public readonly string $return_type = '',
		public readonly string $description = '',
		public readonly ?string $created_at = null,
		public readonly ?string $status_changed_at = null,
		public readonly string $current_placement_name = '',
		public readonly string $current_placement_address = '',
		public readonly string $cancellation_reason = '',
		public readonly string $cancellation_responsible = ''
	) {
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function from_response( array $item ): self {
		return new self(
			self::text( $item, 'return_number' ),
			new ReturnStatus( self::text( $item, 'status' ) ),
			self::text( $item, 'return_external_id' ),
			self::text( $item, 'barcode' ),
			self::text( $item, 'return_type' ),
			self::text( $item, 'description' ),
			self::nullable( $item, 'created_at' ),
			self::nullable( $item, 'status_changed_at' ),
			self::text( $item, 'current_placement_name' ),
			self::text( $item, 'current_placement_address' ),
			self::text( $item, 'cancellation_reason' ),
			self::text( $item, 'cancellation_responsible' )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function text( array $item, string $key ): string {
		return isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ? (string) $item[ $key ] : '';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function nullable( array $item, string $key ): ?string {
		$value = self::text( $item, $key );

		return '' === $value ? null : $value;
	}
}
