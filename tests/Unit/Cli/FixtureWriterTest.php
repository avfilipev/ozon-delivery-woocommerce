<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Spoki\OzonDelivery\Cli\FixtureWriter;
use Spoki\OzonDelivery\Tests\TestCase;

final class FixtureWriterTest extends TestCase {

	private string $directory = '';

	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ozon-fixtures-' . uniqid();

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true )
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) )
		);
	}

	protected function tearDown(): void {
		if ( is_dir( $this->directory ) ) {
			foreach ( (array) glob( $this->directory . '/*' ) as $file ) {
				if ( is_string( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	private function writer(): FixtureWriter {
		return new FixtureWriter( $this->directory );
	}

	public function test_fixture_is_written_as_pretty_json(): void {
		$path = $this->writer()->write( 'posting-info', '{"postings":[{"status":"DELIVERED"}]}' );

		self::assertFileExists( $path );

		$contents = (string) file_get_contents( $path );

		self::assertStringContainsString( "\n", $contents, 'JSON должен быть читаемым, а не в одну строку.' );
		self::assertSame( array( 'postings' => array( array( 'status' => 'DELIVERED' ) ) ), json_decode( $contents, true ) );
	}

	public function test_file_name_comes_from_the_fixture_name(): void {
		$path = $this->writer()->write( 'posting-info', '{}' );

		self::assertStringEndsWith( 'posting-info.json', $path );
	}

	/**
	 * Имя приходит из командной строки — путь наружу вылезать не должен.
	 */
	public function test_name_cannot_escape_the_directory(): void {
		$path = $this->writer()->write( '../../evil', '{}' );

		self::assertStringNotContainsString( '..', $path );
		self::assertStringStartsWith( $this->directory, $path );
	}

	/**
	 * Правило 7: секретов в репозитории быть не должно, а фикстуры туда
	 * коммитятся.
	 */
	public function test_secrets_are_masked_before_saving(): void {
		$path = $this->writer()->write(
			'token',
			'{"access_token":"super-secret","token_type":"Bearer","expires_in":3600}'
		);

		$contents = (string) file_get_contents( $path );

		self::assertStringNotContainsString( 'super-secret', $contents );
		self::assertStringContainsString( 'Bearer', $contents, 'Несекретные поля должны сохраниться.' );
		self::assertStringContainsString( '3600', $contents );
	}

	public function test_nested_secrets_are_masked(): void {
		$path = $this->writer()->write( 'nested', '{"data":{"client_secret":"shh"}}' );

		self::assertStringNotContainsString( 'shh', (string) file_get_contents( $path ) );
	}

	/**
	 * Правило 11 велит записывать живой ответ как есть: неразбираемое тело
	 * лучше сохранить, чем потерять.
	 */
	public function test_non_json_body_is_saved_verbatim(): void {
		$path = $this->writer()->write( 'label', '%PDF-1.4 binary' );

		self::assertStringEndsWith( 'label.txt', $path );
		self::assertSame( '%PDF-1.4 binary', file_get_contents( $path ) );
	}

	public function test_empty_name_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->writer()->write( '   ', '{}' );
	}

	public function test_directory_is_created_when_missing(): void {
		self::assertDirectoryDoesNotExist( $this->directory );

		$this->writer()->write( 'first', '{}' );

		self::assertDirectoryExists( $this->directory );
	}
}
