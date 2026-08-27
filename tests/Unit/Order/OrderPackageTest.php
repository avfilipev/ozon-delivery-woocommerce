<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Mockery;
use Spoki\OzonDelivery\Order\OrderPackage;
use Spoki\OzonDelivery\Shipping\PackageReader;
use Spoki\OzonDelivery\Tests\TestCase;

final class OrderPackageTest extends TestCase {

	private static function product( string $weight = '1.5' ): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_weight' )->andReturn( $weight );
		$product->shouldReceive( 'get_length' )->andReturn( '30' );
		$product->shouldReceive( 'get_width' )->andReturn( '20' );
		$product->shouldReceive( 'get_height' )->andReturn( '10' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );

		return $product;
	}

	private static function item( ?object $product, int $quantity = 1, float $total = 1000.0, float $tax = 0.0 ): object {
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
		$item->shouldReceive( 'get_total' )->andReturn( (string) $total );
		$item->shouldReceive( 'get_total_tax' )->andReturn( (string) $tax );

		return $item;
	}

	/**
	 * @param object[] $items
	 */
	private static function order( array $items ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->andReturn( $items );

		return $order;
	}

	public function test_order_items_become_package_contents(): void {
		$package = OrderPackage::from_order( self::order( array( self::item( self::product(), 2, 3000.0 ) ) ) );

		self::assertCount( 1, $package['contents'] );
		self::assertSame( 2, $package['contents'][0]['quantity'] );
		self::assertSame( 3000.0, $package['contents'][0]['line_total'] );
	}

	/**
	 * Формат совпадает с пакетом корзины, поэтому PackageReader и Packer
	 * работают с заказом без единой правки.
	 */
	public function test_package_is_readable_by_the_cart_reader(): void {
		$package = OrderPackage::from_order( self::order( array( self::item( self::product( '1.5' ), 2, 3000.0 ) ) ) );

		$reader = new PackageReader( 'kg', 'cm' );
		$items  = $reader->items( $package );

		self::assertCount( 1, $items );
		self::assertSame( 1500, $items[0]->dimensions->weight_g );
		self::assertSame( 2, $items[0]->quantity );
		self::assertSame( '3000.00', $reader->subtotal( $package, 'RUB' )->amount );
	}

	public function test_tax_is_carried_over(): void {
		$package = OrderPackage::from_order( self::order( array( self::item( self::product(), 1, 1000.0, 200.0 ) ) ) );

		self::assertSame( 200.0, $package['contents'][0]['line_tax'] );
	}

	/**
	 * Товар мог быть удалён из каталога после оформления заказа.
	 */
	public function test_item_without_a_product_is_skipped(): void {
		$package = OrderPackage::from_order(
			self::order( array( self::item( null ), self::item( self::product() ) ) )
		);

		self::assertCount( 1, $package['contents'] );
	}

	public function test_empty_order_gives_empty_contents(): void {
		self::assertSame( array(), OrderPackage::from_order( self::order( array() ) )['contents'] );
	}
}
