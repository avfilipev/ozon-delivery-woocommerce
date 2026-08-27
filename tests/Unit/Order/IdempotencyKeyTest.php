<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Order\IdempotencyKey;
use Spoki\OzonDelivery\Order\Meta;
use Spoki\OzonDelivery\Tests\TestCase;

final class IdempotencyKeyTest extends TestCase {

	private int $generated = 0;

	/**
	 * Мета «заказов» в памяти, по одному хранилищу на заказ.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stores = array();

	protected function setUp(): void {
		parent::setUp();

		$this->generated = 0;
		$this->stores    = array();

		Functions\when( 'wp_generate_uuid4' )->alias(
			function (): string {
				++$this->generated;

				return sprintf( 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd38%04d', $this->generated );
			}
		);
	}

	/**
	 * @param string $store Какое хранилище меты использует этот объект заказа.
	 */
	private function order( string $store = 'первый' ): object {
		$this->stores[ $store ] ??= array();

		$order = Mockery::mock( 'WC_Order' );

		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, $value ) use ( $store ): void {
				$this->stores[ $store ][ $key ] = $value;
			}
		);
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			fn( string $key ) => $this->stores[ $store ][ $key ] ?? ''
		);
		$order->shouldReceive( 'save' )->andReturn( 1 );

		return $order;
	}

	public function test_key_is_generated_on_first_use(): void {
		$key = IdempotencyKey::for_order( $this->order() );

		self::assertNotSame( '', $key );
		self::assertSame( 1, $this->generated );
	}

	public function test_key_is_stored_on_the_order(): void {
		$key = IdempotencyKey::for_order( $this->order() );

		self::assertSame( $key, $this->stores['первый'][ Meta::IDEMPOTENCY_KEY ] );
	}

	/**
	 * Главное свойство: повторная попытка передать тот же заказ обязана идти
	 * с тем же ключом, иначе Ozon создаст второе отправление.
	 */
	public function test_second_call_returns_the_same_key(): void {
		$order = $this->order();

		$first  = IdempotencyKey::for_order( $order );
		$second = IdempotencyKey::for_order( $order );

		self::assertSame( $first, $second );
		self::assertSame( 1, $this->generated, 'Второй ключ генерироваться не должен.' );
	}

	/**
	 * Ключ переживает перезагрузку: он лежит в мете, а не в памяти процесса.
	 */
	public function test_key_survives_a_new_order_object(): void {
		$first = IdempotencyKey::for_order( $this->order() );

		// Новый запрос, новый объект заказа, та же мета.
		$second = IdempotencyKey::for_order( $this->order() );

		self::assertSame( $first, $second );
	}

	public function test_different_orders_get_different_keys(): void {
		$first  = IdempotencyKey::for_order( $this->order( 'первый' ) );
		$second = IdempotencyKey::for_order( $this->order( 'второй' ) );

		self::assertNotSame( $first, $second );
	}

	public function test_key_looks_like_a_uuid(): void {
		$key = IdempotencyKey::for_order( $this->order() );

		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$key
		);
	}

	/**
	 * Сброс нужен, когда заказ осознанно передают заново как новый.
	 */
	public function test_key_can_be_reset(): void {
		$order = $this->order();

		$first = IdempotencyKey::for_order( $order );

		IdempotencyKey::reset( $order );

		self::assertNotSame( $first, IdempotencyKey::for_order( $order ) );
	}
}
