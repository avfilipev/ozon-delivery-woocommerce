<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Cli;

use InvalidArgumentException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Запись живых ответов Ozon в фикстуры.
 *
 * Правило 11: структуру ответов Ozon нельзя выдумывать даже в тестах —
 * сначала записывается живой ответ, тест пишется против него. Этот класс и
 * есть тот инструмент.
 *
 * Секреты вычищаются перед записью: фикстуры коммитятся в репозиторий, а по
 * правилу 7 токенов и ключей там быть не должно.
 */
final class FixtureWriter {

	public function __construct( private readonly string $directory ) {
	}

	public static function create(): self {
		return new self( dirname( __DIR__, 2 ) . '/tests/Fixtures' );
	}

	/**
	 * @return string Путь до записанного файла.
	 *
	 * @throws InvalidArgumentException Пустое имя фикстуры.
	 */
	public function write( string $name, string $body ): string {
		$safe_name = sanitize_key( trim( $name ) );

		if ( '' === $safe_name ) {
			throw new InvalidArgumentException( 'Имя фикстуры не может быть пустым.' );
		}

		if ( ! is_dir( $this->directory ) ) {
			wp_mkdir_p( $this->directory );
		}

		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			$contents = (string) wp_json_encode(
				( new Logger() )->mask( $decoded ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$path     = $this->directory . '/' . $safe_name . '.json';
		} else {
			// Не JSON — например этикетка. Сохраняем как есть: потерять живой
			// ответ хуже, чем сохранить нечитаемый.
			$contents = $body;
			$path     = $this->directory . '/' . $safe_name . '.txt';
		}

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}
}
