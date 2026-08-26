<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Support;

final class Logger {

	private const SOURCE = 'ozon-delivery';

	private const MASK = '***';

	/**
	 * Ключи, значения которых нельзя писать в лог в открытом виде.
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = array( 'client_secret', 'access_token', 'cookie' );

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$context['source'] = self::SOURCE;

		wc_get_logger()->log( $level, $message, $this->mask( $context ) );
	}

	/**
	 * Рекурсивно маскирует секреты в контексте лога. x-o3-trace-id и остальные
	 * ключи проходят без изменений — его всегда спрашивает поддержка Ozon.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function mask( array $context ): array {
		$masked = array();

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$masked[ $key ] = $this->mask( $value );
				continue;
			}

			$masked[ $key ] = $this->is_secret_key( (string) $key ) ? self::MASK : $value;
		}

		return $masked;
	}

	private function is_secret_key( string $key ): bool {
		return in_array( strtolower( $key ), self::SECRET_KEYS, true );
	}
}
