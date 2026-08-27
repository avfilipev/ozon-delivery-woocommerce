<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Admin;

/**
 * Чистая логика экрана настроек: список полей, санитизация, маскирование
 * секрета для вывода. Не зависит от WooCommerce, обвязка — SettingsPage.
 */
final class Settings {

	public const FIELD_CLIENT_ID          = 'ozon_delivery_client_id';
	public const FIELD_CLIENT_SECRET      = 'ozon_delivery_client_secret';
	public const FIELD_SCOPE              = 'ozon_delivery_scope';
	public const FIELD_SHIPMENT_METHOD_ID = 'ozon_delivery_shipment_method_id';
	public const FIELD_DRY_RUN            = 'ozon_delivery_dry_run';

	public const FIELD_DEFAULT_WEIGHT    = 'ozon_delivery_default_weight';
	public const FIELD_DEFAULT_LENGTH    = 'ozon_delivery_default_length';
	public const FIELD_DEFAULT_WIDTH     = 'ozon_delivery_default_width';
	public const FIELD_DEFAULT_HEIGHT    = 'ozon_delivery_default_height';
	public const FIELD_PACKAGING_PADDING = 'ozon_delivery_packaging_padding';
	public const FIELD_DECLARED_PERCENT  = 'ozon_delivery_declared_value_percent';

	/**
	 * @return array<int, array{id: string, type: string, default: string}>
	 */
	public function get_fields(): array {
		return array(
			array(
				'id'      => self::FIELD_CLIENT_ID,
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'      => self::FIELD_CLIENT_SECRET,
				'type'    => 'ozon_secret',
				'default' => '',
			),
			array(
				'id'      => self::FIELD_SCOPE,
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'      => self::FIELD_SHIPMENT_METHOD_ID,
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'      => self::FIELD_DRY_RUN,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			// Габариты по умолчанию — в тех единицах, что настроены в WooCommerce.
			// Перевод в граммы и миллиметры делает Shipping\Dimensions.
			array(
				'id'      => self::FIELD_DEFAULT_WEIGHT,
				'type'    => 'text',
				'default' => '0.5',
			),
			array(
				'id'      => self::FIELD_DEFAULT_LENGTH,
				'type'    => 'text',
				'default' => '20',
			),
			array(
				'id'      => self::FIELD_DEFAULT_WIDTH,
				'type'    => 'text',
				'default' => '15',
			),
			array(
				'id'      => self::FIELD_DEFAULT_HEIGHT,
				'type'    => 'text',
				'default' => '10',
			),
			array(
				'id'      => self::FIELD_PACKAGING_PADDING,
				'type'    => 'text',
				'default' => '10',
			),
			array(
				'id'      => self::FIELD_DECLARED_PERCENT,
				'type'    => 'text',
				'default' => '100',
			),
		);
	}

	/**
	 * @param array<string, string> $posted  Данные из формы (уже без слэшей).
	 * @param array<string, string> $existing Текущие сохранённые значения.
	 * @return array<string, string>
	 */
	public function sanitize( array $posted, array $existing ): array {
		$sanitized = array();

		foreach ( $this->get_fields() as $field ) {
			$id = $field['id'];

			if ( self::FIELD_DRY_RUN === $id ) {
				$sanitized[ $id ] = empty( $posted[ $id ] ) ? 'no' : 'yes';
				continue;
			}

			if ( self::FIELD_CLIENT_SECRET === $id && empty( $posted[ $id ] ) ) {
				$sanitized[ $id ] = $existing[ $id ] ?? '';
				continue;
			}

			$sanitized[ $id ] = sanitize_text_field( (string) ( $posted[ $id ] ?? '' ) );
		}

		return $sanitized;
	}

	/**
	 * Секрет никогда не выводится в поле в открытом виде — только маркер того,
	 * что значение уже сохранено.
	 */
	public function mask_secret_for_display( string $secret ): string {
		return '' === $secret ? '' : str_repeat( '•', 12 );
	}
}
