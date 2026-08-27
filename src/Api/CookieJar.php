<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

/**
 * Хранилище cookie анти-DDoS модуля testcookie.
 *
 * Ozon закрывает API модулем testcookie: сервер отвечает редиректом 302/307 с
 * заголовками Location и Set-Cookie, клиент обязан повторить запрос с тем же
 * телом и приложенным cookie, а затем слать cookie в последующих запросах.
 * Значение уникально, зашифровано и может измениться без уведомления, поэтому
 * jar всегда перезаписывает cookie с тем же именем.
 *
 * @see docs/API.md, раздел «Защита testcookie»
 */
final class CookieJar {

	public const TRANSIENT = 'ozon_delivery_testcookie';

	/**
	 * 12 часов. Cookie может протухнуть раньше — тогда Ozon снова ответит
	 * редиректом, и Transport обновит значение.
	 */
	public const TTL = 43200;

	/**
	 * @var array<string, string>|null Ленивый кэш на время запроса.
	 */
	private ?array $cookies = null;

	/**
	 * Значение заголовка Cookie для исходящего запроса. Пустая строка — cookie нет.
	 */
	public function header(): string {
		$pairs = array();

		foreach ( $this->all() as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}

		return implode( '; ', $pairs );
	}

	/**
	 * Разбирает один или несколько заголовков Set-Cookie и сохраняет их.
	 *
	 * @param string|string[] $set_cookie Значение заголовка Set-Cookie из ответа.
	 */
	public function remember( array|string $set_cookie ): void {
		$cookies = $this->all();

		foreach ( (array) $set_cookie as $header ) {
			$pair = $this->parse( (string) $header );

			if ( null === $pair ) {
				continue;
			}

			[ $name, $value ] = $pair;

			$cookies[ $name ] = $value;
		}

		$this->cookies = $cookies;

		set_transient( self::TRANSIENT, $cookies, self::TTL );
	}

	/**
	 * Сбрасывает cookie: вызывается, когда сервер отвечает редиректом там, где
	 * его быть не должно, то есть сохранённое значение больше не принимается.
	 */
	public function forget(): void {
		$this->cookies = array();

		delete_transient( self::TRANSIENT );
	}

	/**
	 * @return array<string, string>
	 */
	private function all(): array {
		if ( null === $this->cookies ) {
			$stored = get_transient( self::TRANSIENT );

			$this->cookies = is_array( $stored ) ? $stored : array();
		}

		return $this->cookies;
	}

	/**
	 * `b2c_cookie=abc123; path=/; HttpOnly` → `['b2c_cookie', 'abc123']`.
	 *
	 * @return array{0: string, 1: string}|null null, если заголовок разобрать нельзя.
	 */
	private function parse( string $header ): ?array {
		$pair = trim( explode( ';', $header, 2 )[0] );

		if ( ! str_contains( $pair, '=' ) ) {
			return null;
		}

		[ $name, $value ] = explode( '=', $pair, 2 );

		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		return array( $name, trim( $value ) );
	}
}
