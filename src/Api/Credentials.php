<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

use Spoki\OzonDelivery\Admin\Settings;

/**
 * Ключи частного приложения Ozon Доставки.
 *
 * Создаются в личном кабинете: Настройки → Управление частными приложениями.
 */
final class Credentials {

	public function __construct(
		public readonly string $client_id,
		public readonly string $client_secret,
		public readonly string $scope = ''
	) {
	}

	/**
	 * Читает ключи из настроек плагина.
	 */
	public static function from_options(): self {
		return new self(
			(string) get_option( Settings::FIELD_CLIENT_ID, '' ),
			(string) get_option( Settings::FIELD_CLIENT_SECRET, '' ),
			(string) get_option( Settings::FIELD_SCOPE, '' )
		);
	}

	/**
	 * Без client_id и client_secret идти за токеном бессмысленно.
	 */
	public function are_complete(): bool {
		return '' !== $this->client_id && '' !== $this->client_secret;
	}
}
