<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Checkout;

use Spoki\OzonDelivery\Api\Endpoints\Delivery;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Проверка покупателя по телефону, с кэшем.
 *
 * Доставка Ozon доступна только зарегистрированным на Ozon покупателям.
 * Чекаут перерисовывается на каждое изменение поля, а телефон меняется редко,
 * поэтому ответ кэшируется по нормализованному номеру.
 */
final class ClientCheck {

	private const CACHE_PREFIX = 'ozon_delivery_client_';

	public const CACHE_TTL = 3600;

	public function __construct(
		private readonly Delivery $delivery,
		private readonly Logger $logger
	) {
	}

	public function can_deliver_to( string $phone_number ): bool {
		$normalised = $this->normalise( $phone_number );

		if ( '' === $normalised ) {
			return false;
		}

		$key    = self::CACHE_PREFIX . md5( $normalised );
		$cached = get_transient( $key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return 'yes' === $cached;
		}

		try {
			$allowed = $this->delivery->can_deliver_to( $phone_number );
		} catch ( ApiException $e ) {
			$this->logger->log(
				'warning',
				'Не удалось проверить покупателя в Ozon',
				array( 'error' => $e->getMessage() )
			);

			// Ответа нет — прятать метод нельзя: настоящей проверкой всё равно
			// будет order/checkout, который при той же недоступности сам не
			// отдаст тариф. И такой «ответ» не кэшируется.
			return true;
		}

		set_transient( $key, $allowed ? 'yes' : 'no', self::CACHE_TTL );

		return $allowed;
	}

	/**
	 * Один и тот же номер приходит в разной записи: +7 (900) 000-00-00 и
	 * +79000000000 — это один покупатель и один запрос.
	 */
	private function normalise( string $phone_number ): string {
		return (string) preg_replace( '/\D+/', '', $phone_number );
	}
}
