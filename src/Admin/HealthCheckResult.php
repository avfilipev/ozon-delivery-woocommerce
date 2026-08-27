<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

/**
 * Итог проверки подключения: что показать администратору.
 */
final class HealthCheckResult {

	public function __construct(
		public readonly bool $ok,
		public readonly string $message
	) {
	}

	public static function ok( string $message ): self {
		return new self( true, $message );
	}

	public static function failed( string $message ): self {
		return new self( false, $message );
	}
}
