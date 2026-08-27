<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Spoki\OzonDelivery\Shipping\CartItem;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Shipping\Packer;
use Spoki\OzonDelivery\Tests\TestCase;

final class PackerTest extends TestCase {

	private static function fallback(): Dimensions {
		return new Dimensions( 500, 200, 150, 100 );
	}

	/**
	 * @return CartItem[]
	 */
	private static function items( CartItem ...$items ): array {
		return $items;
	}

	public function test_single_item_keeps_its_own_dimensions(): void {
		$packed = ( new Packer( self::fallback() ) )->pack(
			self::items(
				new CartItem( new Dimensions( 1200, 300, 200, 100 ), 1 )
			)
		);

		self::assertSame( 1200, $packed->weight_g );
		self::assertSame( 300, $packed->length_mm );
	}

	/**
	 * Вес умножается на количество, стороны — нет: три одинаковые коробки
	 * весят втрое больше, но не становятся втрое длиннее.
	 */
	public function test_quantity_multiplies_weight_but_not_sides(): void {
		$packed = ( new Packer( self::fallback() ) )->pack(
			self::items(
				new CartItem( new Dimensions( 1000, 300, 200, 100 ), 3 )
			)
		);

		self::assertSame( 3000, $packed->weight_g );
		self::assertSame( 300, $packed->length_mm );
	}

	public function test_several_items_sum_weight_and_take_the_largest_side(): void {
		$packed = ( new Packer( self::fallback() ) )->pack(
			self::items(
				new CartItem( new Dimensions( 1000, 300, 100, 50 ), 1 ),
				new CartItem( new Dimensions( 700, 200, 250, 40 ), 2 )
			)
		);

		self::assertSame( 1000 + 1400, $packed->weight_g );
		self::assertSame( 300, $packed->length_mm );
		self::assertSame( 250, $packed->width_mm );
		self::assertSame( 50, $packed->height_mm );
	}

	/**
	 * Габариты у товара заполнены далеко не всегда, а Ozon требует их всегда.
	 */
	public function test_item_without_dimensions_falls_back_to_defaults(): void {
		$packed = ( new Packer( self::fallback() ) )->pack(
			self::items(
				new CartItem( new Dimensions( 0, 0, 0, 0 ), 1 )
			)
		);

		self::assertSame( 500, $packed->weight_g );
		self::assertSame( 200, $packed->length_mm );
	}

	public function test_empty_cart_falls_back_to_defaults(): void {
		$packed = ( new Packer( self::fallback() ) )->pack( array() );

		self::assertSame( self::fallback()->to_array(), $packed->to_array() );
	}

	public function test_padding_is_added_to_every_side(): void {
		$packed = ( new Packer( self::fallback(), 20 ) )->pack(
			self::items(
				new CartItem( new Dimensions( 1000, 300, 200, 100 ), 1 )
			)
		);

		self::assertSame( 320, $packed->length_mm );
		self::assertSame( 220, $packed->width_mm );
		self::assertSame( 120, $packed->height_mm );
	}

	public function test_padding_does_not_change_weight(): void {
		$packed = ( new Packer( self::fallback(), 20 ) )->pack(
			self::items(
				new CartItem( new Dimensions( 1000, 300, 200, 100 ), 1 )
			)
		);

		self::assertSame( 1000, $packed->weight_g );
	}

	/**
	 * Отправление нулевого веса Ozon не примет, а расчёт по нему бессмыслен.
	 */
	public function test_result_is_never_empty(): void {
		$packed = ( new Packer( new Dimensions( 0, 0, 0, 0 ) ) )->pack( array() );

		self::assertFalse( $packed->is_empty() );
		self::assertGreaterThan( 0, $packed->weight_g );
		self::assertGreaterThan( 0, $packed->length_mm );
	}

	public function test_zero_quantity_item_is_skipped(): void {
		$packed = ( new Packer( self::fallback() ) )->pack(
			self::items(
				new CartItem( new Dimensions( 9000, 900, 900, 900 ), 0 )
			)
		);

		self::assertSame( self::fallback()->to_array(), $packed->to_array() );
	}
}
