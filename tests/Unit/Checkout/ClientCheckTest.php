<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Delivery;
use Spoki\OzonDelivery\Api\TokenStore;
use Spoki\OzonDelivery\Checkout\ClientCheck;
use Spoki\OzonDelivery\Support\Logger;
use Spoki\OzonDelivery\Tests\TestCase;
use Spoki\OzonDelivery\Tests\WpHttpStubs;

final class ClientCheckTest extends TestCase {

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

	private function check(): ClientCheck {
		return new ClientCheck( new Delivery( ClientFactory::create() ), new Logger() );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function json( array $payload ): array {
		return self::response( 200, array(), (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_registered_customer_passes(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		self::assertTrue( $this->check()->can_deliver_to( '+79000000000' ) );
	}

	public function test_unknown_customer_is_refused(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => false ) ) ) );

		self::assertFalse( $this->check()->can_deliver_to( '+79000000000' ) );
	}

	/**
	 * Чекаут перерисовывается на каждое изменение поля, а телефон меняется
	 * редко: без кэша это был бы запрос к Ozon на каждый чих.
	 */
	public function test_answer_is_cached(): void {
		$this->queue(
			array(
				self::json( array( 'can_be_delivered' => true ) ),
				self::json( array( 'can_be_delivered' => false ) ),
			)
		);

		self::assertTrue( $this->check()->can_deliver_to( '+79000000000' ) );
		self::assertTrue( $this->check()->can_deliver_to( '+79000000000' ) );
		self::assertCount( 1, $this->calls );
	}

	public function test_different_phone_is_checked_separately(): void {
		$this->queue(
			array(
				self::json( array( 'can_be_delivered' => true ) ),
				self::json( array( 'can_be_delivered' => false ) ),
			)
		);

		self::assertTrue( $this->check()->can_deliver_to( '+79000000000' ) );
		self::assertFalse( $this->check()->can_deliver_to( '+79111111111' ) );
	}

	/**
	 * Номер в разной записи — тот же покупатель, лишний запрос не нужен.
	 */
	public function test_formatting_does_not_change_the_answer(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		$this->check()->can_deliver_to( '+7 (900) 000-00-00' );
		$this->check()->can_deliver_to( '+79000000000' );

		self::assertCount( 1, $this->calls );
	}

	public function test_empty_phone_is_refused_without_a_request(): void {
		$this->queue( array( self::json( array( 'can_be_delivered' => true ) ) ) );

		self::assertFalse( $this->check()->can_deliver_to( '' ) );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * Если Ozon недоступен, знать ответ неоткуда. Прятать метод нельзя:
	 * настоящей проверкой всё равно будет order/checkout, который при той же
	 * недоступности сам не отдаст тариф.
	 */
	public function test_api_failure_does_not_hide_the_method(): void {
		$this->queue( array_fill( 0, 4, self::response( 500, array(), '{}' ) ) );

		self::assertTrue( $this->check()->can_deliver_to( '+79000000000' ) );
	}

	public function test_api_failure_is_not_cached_as_a_verdict(): void {
		$this->queue(
			array(
				self::response( 500, array(), '{}' ),
				self::response( 500, array(), '{}' ),
				self::response( 500, array(), '{}' ),
				self::json( array( 'can_be_delivered' => false ) ),
			)
		);

		$this->check()->can_deliver_to( '+79000000000' );

		self::assertFalse( $this->check()->can_deliver_to( '+79000000000' ) );
	}
}
