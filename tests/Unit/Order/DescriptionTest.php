<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Mockery;
use Spoki\OzonDelivery\Order\Description;
use Spoki\OzonDelivery\Tests\TestCase;

final class DescriptionTest extends TestCase {

	private static function order( string $number = '123' ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_order_number' )->andReturn( $number );

		return $order;
	}

	public function test_description_names_the_order(): void {
		$description = Description::for_order( self::order( '123' ) );

		self::assertStringContainsString( '123', $description );
	}

	/**
	 * `description` — обязательное поле order/create, пустым быть не может.
	 */
	public function test_description_is_never_empty(): void {
		self::assertNotSame( '', Description::for_order( self::order( '' ) ) );
	}

	/**
	 * Названия товаров в описание намеренно не попадают: его видят и
	 * покупатель, и логистика. Кому нужно иначе — добавит фильтром.
	 */
	public function test_description_does_not_leak_product_names(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '123' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );

		$description = Description::for_order( $order );

		self::assertStringNotContainsString( 'Товар', $description );
	}

	/**
	 * Ozon ограничивает длину поля, а фильтр может вернуть что угодно.
	 */
	public function test_description_is_trimmed_to_a_sane_length(): void {
		$order = self::order( str_repeat( 'X', 1000 ) );

		self::assertLessThanOrEqual( Description::MAX_LENGTH, mb_strlen( Description::for_order( $order ) ) );
	}
}
