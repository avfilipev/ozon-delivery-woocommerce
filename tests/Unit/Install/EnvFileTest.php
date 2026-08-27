<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Install;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Admin\Settings;
use Spoki\OzonDelivery\Install\EnvFile;
use Spoki\OzonDelivery\Tests\TestCase;

final class EnvFileTest extends TestCase {

	private string $path = '';

	/**
	 * @var array<string, string>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->path    = sys_get_temp_dir() . '/ozon-env-' . uniqid() . '.local';
		$this->options = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = '' ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}

		parent::tearDown();
	}

	private function write_env( string $contents ): void {
		file_put_contents( $this->path, $contents );
	}

	private function env(): EnvFile {
		return new EnvFile( $this->path );
	}

	public function test_values_are_read_into_options(): void {
		$this->write_env(
			"OZON_CLIENT_ID=id-from-file\nOZON_CLIENT_SECRET=secret-from-file\n"
		);

		$filled = $this->env()->fill_missing_options();

		self::assertSame( 2, $filled );
		self::assertSame( 'id-from-file', $this->options[ Settings::FIELD_CLIENT_ID ] );
		self::assertSame( 'secret-from-file', $this->options[ Settings::FIELD_CLIENT_SECRET ] );
	}

	public function test_scope_and_shipment_method_are_read(): void {
		$this->write_env(
			"OZON_SCOPE=delivery-api.all\nOZON_SHIPMENT_METHOD_ID=777\n"
		);

		$this->env()->fill_missing_options();

		self::assertSame( 'delivery-api.all', $this->options[ Settings::FIELD_SCOPE ] );
		self::assertSame( '777', $this->options[ Settings::FIELD_SHIPMENT_METHOD_ID ] );
	}

	/**
	 * Главное правило: то, что задано в админке, файлом не перетирается.
	 * Иначе правка ключей через интерфейс молча откатывалась бы.
	 */
	public function test_existing_option_is_never_overwritten(): void {
		$this->options[ Settings::FIELD_CLIENT_ID ] = 'id-from-admin';

		$this->write_env( "OZON_CLIENT_ID=id-from-file\n" );

		$filled = $this->env()->fill_missing_options();

		self::assertSame( 0, $filled );
		self::assertSame( 'id-from-admin', $this->options[ Settings::FIELD_CLIENT_ID ] );
	}

	public function test_comments_and_blank_lines_are_ignored(): void {
		$this->write_env(
			"# комментарий\n\n  \nOZON_CLIENT_ID=id\n# OZON_CLIENT_SECRET=не-читать\n"
		);

		$this->env()->fill_missing_options();

		self::assertSame( 'id', $this->options[ Settings::FIELD_CLIENT_ID ] );
		self::assertArrayNotHasKey( Settings::FIELD_CLIENT_SECRET, $this->options );
	}

	/**
	 * @dataProvider quoted_value_provider
	 */
	public function test_quotes_are_stripped( string $line, string $expected ): void {
		$this->write_env( $line . "\n" );

		$this->env()->fill_missing_options();

		self::assertSame( $expected, $this->options[ Settings::FIELD_CLIENT_ID ] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function quoted_value_provider(): array {
		return array(
			'двойные кавычки' => array( 'OZON_CLIENT_ID="quoted"', 'quoted' ),
			'одинарные'       => array( "OZON_CLIENT_ID='quoted'", 'quoted' ),
			'пробелы вокруг'  => array( 'OZON_CLIENT_ID =  spaced  ', 'spaced' ),
			'без кавычек'     => array( 'OZON_CLIENT_ID=plain', 'plain' ),
		);
	}

	public function test_empty_value_is_not_written(): void {
		$this->write_env( "OZON_CLIENT_ID=\n" );

		$filled = $this->env()->fill_missing_options();

		self::assertSame( 0, $filled );
		self::assertArrayNotHasKey( Settings::FIELD_CLIENT_ID, $this->options );
	}

	public function test_unknown_keys_are_ignored(): void {
		$this->write_env( "SOMETHING_ELSE=value\nOZON_CLIENT_ID=id\n" );

		$this->env()->fill_missing_options();

		self::assertArrayNotHasKey( 'SOMETHING_ELSE', $this->options );
		self::assertSame( 'id', $this->options[ Settings::FIELD_CLIENT_ID ] );
	}

	/**
	 * Файла нет — это норма: он только для локальной разработки.
	 */
	public function test_missing_file_is_not_an_error(): void {
		self::assertSame( 0, $this->env()->fill_missing_options() );
	}

	public function test_file_is_not_read_when_keys_are_already_set(): void {
		$this->options[ Settings::FIELD_CLIENT_ID ]     = 'id';
		$this->options[ Settings::FIELD_CLIENT_SECRET ] = 'secret';

		$this->write_env( "OZON_SCOPE=delivery-api.all\n" );

		$filled = $this->env()->fill_missing_options();

		self::assertSame( 0, $filled, 'Когда ключи уже заданы, файл трогать незачем.' );
		self::assertArrayNotHasKey( Settings::FIELD_SCOPE, $this->options );
	}
}
