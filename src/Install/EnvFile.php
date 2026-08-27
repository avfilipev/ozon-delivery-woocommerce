<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Install;

use Spoki\OzonDelivery\Admin\Settings;

/**
 * Чтение ключей из `.env.local` в опции WordPress.
 *
 * Удобство локальной разработки: ключи кладутся в файл, а не вводятся руками
 * через админку при каждом пересоздании окружения. Файл в `.gitignore`.
 *
 * Два правила, от которых зависит безопасность:
 *
 * 1. **Заданное в админке не перетирается.** Файл заполняет только пустые
 *    опции — иначе правка ключей через интерфейс молча откатывалась бы к
 *    содержимому файла.
 * 2. **Пока ключи заданы, файл не читается вообще.** Проверка идёт по
 *    опциям (они автозагружены), поэтому в обычном запросе обращения к диску
 *    не происходит.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */
final class EnvFile {

	/**
	 * Переменная окружения → опция плагина.
	 *
	 * @var array<string, string>
	 */
	private const MAP = array(
		'OZON_CLIENT_ID'          => Settings::FIELD_CLIENT_ID,
		'OZON_CLIENT_SECRET'      => Settings::FIELD_CLIENT_SECRET,
		'OZON_SCOPE'              => Settings::FIELD_SCOPE,
		'OZON_SHIPMENT_METHOD_ID' => Settings::FIELD_SHIPMENT_METHOD_ID,
	);

	public function __construct( private readonly string $path ) {
	}

	public static function create(): self {
		return new self( dirname( __DIR__, 2 ) . '/.env.local' );
	}

	/**
	 * Заполняет пустые опции значениями из файла.
	 *
	 * @return int Сколько опций заполнено.
	 */
	public function fill_missing_options(): int {
		// Ключи уже заданы — к диску не обращаемся вовсе.
		if ( '' !== (string) get_option( Settings::FIELD_CLIENT_ID, '' )
			&& '' !== (string) get_option( Settings::FIELD_CLIENT_SECRET, '' )
		) {
			return 0;
		}

		$filled = 0;

		foreach ( $this->read() as $name => $value ) {
			$option = self::MAP[ $name ] ?? null;

			if ( null === $option || '' === $value ) {
				continue;
			}

			// То, что задано в админке, файлом не перетирается.
			if ( '' !== (string) get_option( $option, '' ) ) {
				continue;
			}

			update_option( $option, sanitize_text_field( $value ) );

			++$filled;
		}

		return $filled;
	}

	/**
	 * @return array<string, string>
	 */
	private function read(): array {
		if ( ! is_readable( $this->path ) ) {
			return array();
		}

		$contents = file_get_contents( $this->path );

		if ( false === $contents ) {
			return array();
		}

		$values = array();

		$lines = preg_split( '/\R/', $contents );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $name, $value ] = explode( '=', $line, 2 );

			$values[ trim( $name ) ] = $this->unquote( trim( $value ) );
		}

		return $values;
	}

	private function unquote( string $value ): string {
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];

			if ( $first === $last && ( '"' === $first || "'" === $first ) ) {
				return substr( $value, 1, -1 );
			}
		}

		return $value;
	}
}
