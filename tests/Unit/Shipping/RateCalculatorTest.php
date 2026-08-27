<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Orders;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Shipping\RateCalculator;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class RateCalculatorTest extends TestCase {

	use WpHttpStubs;

	protected function setUp(): void {
		parent::setUp();

		$this->stub_wp_http();
		$this->stub_instant_retries();

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = '' ) => in_array(
				$name,
				array( 'ozon_delivery_client_id', 'ozon_delivery_client_secret' ),
				true
			) ? 'set' : $default_value
		);

		$this->transients[ TokenStore::TRANSIENT ] = 'tok';
	}

	private function calculator(): RateCalculator {
		return new RateCalculator( new Orders( ClientFactory::create() ), new Logger() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function quote_response( string $cost = '350.00' ): array {
		return self::json(
			array(
				'results' => array(
					array(
						'request_id' => 1,
						'posting'    => array(
							'estimated_delivery_cost' => array(
								'amount'        => $cost,
								'currency_code' => 'RUB',
							),
							'estimated_delivery_days' => 3,
						),
					),
				),
			)
		);
	}

	private function quote(): \Spoki\OzonDelivery\Shipping\CheckoutQuote {
		return $this->calculator()->quote(
			'+79000000000',
			777,
			new Dimensions( 1200, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::point( 4242 )
		);
	}

	public function test_quote_is_calculated(): void {
		$this->queue( array( self::quote_response() ) );

		$quote = $this->quote();

		self::assertTrue( $quote->available );
		self::assertSame( '350.00', $quote->delivery_cost?->amount );
	}

	/**
	 * WooCommerce пересчитывает доставку на каждое изменение корзины и на
	 * каждую загрузку чекаута. Без кэша это был бы запрос к Ozon каждый раз.
	 */
	public function test_repeated_quote_for_the_same_package_is_served_from_cache(): void {
		$this->queue( array( self::quote_response( '350.00' ), self::quote_response( '999.00' ) ) );

		$first  = $this->quote();
		$second = $this->quote();

		self::assertSame( '350.00', $first->delivery_cost?->amount );
		self::assertSame( '350.00', $second->delivery_cost?->amount );
		self::assertCount( 1, $this->calls );
	}

	public function test_changed_package_is_recalculated(): void {
		$this->queue( array( self::quote_response( '350.00' ), self::quote_response( '999.00' ) ) );

		$this->quote();

		$changed = $this->calculator()->quote(
			'+79000000000',
			777,
			new Dimensions( 5000, 300, 200, 100 ),
			new Money( '2500.00', 'RUB' ),
			Destination::point( 4242 )
		);

		self::assertSame( '999.00', $changed->delivery_cost?->amount );
		self::assertCount( 2, $this->calls );
	}

	/**
	 * Правило 5: ошибка расчёта не должна валить чекаут. Наружу выходит
	 * непригодный расчёт с причиной, а не исключение.
	 */
	public function test_api_failure_returns_an_unavailable_quote_instead_of_throwing(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		$quote = $this->quote();

		self::assertFalse( $quote->available );
		self::assertNotSame( '', $quote->message );
	}

	public function test_network_failure_returns_an_unavailable_quote(): void {
		$this->queue( array_fill( 0, 4, new \WP_Error( 'http_request_failed', 'таймаут' ) ) );

		$quote = $this->quote();

		self::assertFalse( $quote->available );
	}

	public function test_error_inside_two_hundred_is_passed_through(): void {
		$this->queue(
			array(
				self::json(
					array(
						'results' => array(
							array(
								'request_id' => 1,
								'error'      => array( 'code' => 'DPRE' ),
							),
						),
					)
				),
			)
		);

		$quote = $this->quote();

		self::assertFalse( $quote->available );
		self::assertSame( 'DPRE', $quote->error_code );
	}

	/**
	 * Неудачу тоже надо кэшировать, иначе каждое обновление корзины будет
	 * долбить лежащий API. Но ненадолго — чтобы восстановление заметили быстро.
	 */
	public function test_failure_is_cached_for_a_shorter_time_than_success(): void {
		$ttls = array();

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl ) use ( &$ttls ): bool {
				$this->transients[ $key ] = $value;
				$ttls[]                   = $ttl;
				return true;
			}
		);

		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );
		$this->quote();
		$failure_ttl = end( $ttls );

		$this->transients = array( TokenStore::TRANSIENT => 'tok' );
		$ttls             = array();

		$this->queue( array( self::quote_response() ) );
		$this->quote();
		$success_ttl = end( $ttls );

		self::assertIsInt( $failure_ttl );
		self::assertIsInt( $success_ttl );
		self::assertLessThan( $success_ttl, $failure_ttl );
	}

	public function test_failure_is_not_retried_while_cached(): void {
		$this->queue( array_fill( 0, 8, self::response( 500, array(), '{}' ) ) );

		$this->quote();
		$calls_after_first = count( $this->calls );

		$this->quote();

		self::assertCount( $calls_after_first, $this->calls );
	}
}
