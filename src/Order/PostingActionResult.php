<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Api\ErrorCodes;

/**
 * Итог действия над отправлением: подтверждения или отмены.
 *
 * Оба метода отвечают 200 без тела, но ошибка может прийти внутри этого же
 * 200 — правило 3. Настоящий результат всё равно смотрится через
 * posting/info: так велит сама документация Ozon.
 */
final class PostingActionResult {

	public function __construct(
		public readonly bool $succeeded,
		public readonly string $error_code = '',
		public readonly string $message = '',
		public readonly bool $needs_human = false
	) {
	}

	/**
	 * @param array<string, mixed> $response
	 */
	public static function from_response( array $response ): self {
		$error = isset( $response['error'] ) && is_array( $response['error'] ) ? $response['error'] : array();

		if ( array() === $error ) {
			return new self( true );
		}

		$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';

		return new self(
			false,
			$code,
			ErrorCodes::message( $code, $message ),
			// Недостаточный баланс и заблокированный кабинет повтором не лечатся.
			ErrorCodes::is_balance_problem( $code ) || ErrorCodes::is_account_problem( $code )
		);
	}
}
