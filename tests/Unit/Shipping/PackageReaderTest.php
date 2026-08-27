<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Mockery;
use Spoki\OzonDelivery\Shipping\PackageReader;
use Spoki\OzonDelivery\Tests\TestCase;

final class PackageReaderTest extends TestCase {

	/**
	 * WC_Product без загруженного WooCommerce не существует — Mockery умеет
	 * подменять и несуществующие классы.
	 */
	private static function product(
		string $weight = '1.5',
		string $length = '30',
		string $width = '20',
		string $height = '10',
		bool $needs_shipping = true
	): object {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_weight' )->andReturn( $weight );
		$product->shouldReceive( 'get_length' )->andReturn( $length );
		$product->shouldReceive( 'get_width' )->andReturn( $width );
		$product->shouldReceive( 'get_height' )->andReturn( $height );
		$product->shouldReceive( 'needs_shipping' )->andReturn( $needs_shipping );

		return $product;
	}

	/**
	 * @param array<int, array<string, mixed>> $contents
	 * @return array<string, mixed>
	 */
	private static function package( array $contents ): array {
		return array( 'contents' => $contents );
	}

	private function reader(): PackageReader {
		return new PackageReader( 'kg', 'cm' );
	}

	public function test_product_dimensions_are_converted_to_ozon_units(): void {
		$items = $this->reader()->items(
			self::package(
				array(
					array(
						'data'     => self::product( '1.5', '30', '20', '10' ),
						'quantity' => 1,
					),
				)
			)
		);

		self::assertCount( 1, $items );
		self::assertSame( 1500, $items[0]->dimensions->weight_g );
		self::assertSame( 300, $items[0]->dimensions->length_mm );
		self::assertSame( 200, $items[0]->dimensions->width_mm );
		self::assertSame( 100, $items[0]->dimensions->height_mm );
	}

	public function test_quantity_is_carried_over(): void {
		$items = $this->reader()->items(
			self::package(
				array(
					array(
						'data'     => self::product(),
						'quantity' => 4,
					),
				)
			)
		);

		self::assertSame( 4, $items[0]->quantity );
	}

	/**
	 * Товар без габаритов — обычное дело: значения по умолчанию подставит
	 * Packer, здесь получаются нули.
	 */
	public function test_product_without_dimensions_yields_zeroes(): void {
		$items = $this->reader()->items(
			self::package(
				array(
					array(
						'data'     => self::product( '', '', '', '' ),
						'quantity' => 1,
					),
				)
			)
		);

		self::assertTrue( $items[0]->dimensions->is_empty() );
	}

	/**
	 * Виртуальные товары не едут физически и в габариты попадать не должны.
	 */
	public function test_items_that_do_not_need_shipping_are_skipped(): void {
		$items = $this->reader()->items(
			self::package(
				array(
					array(
						'data'     => self::product( '1', '10', '10', '10', false ),
						'quantity' => 1,
					),
					array(
						'data'     => self::product(),
						'quantity' => 1,
					),
				)
			)
		);

		self::assertCount( 1, $items );
	}

	public function test_empty_package_gives_no_items(): void {
		self::assertSame( array(), $this->reader()->items( self::package( array() ) ) );
	}

	public function test_package_without_contents_gives_no_items(): void {
		self::assertSame( array(), $this->reader()->items( array() ) );
	}

	public function test_entries_without_a_product_are_skipped(): void {
		$items = $this->reader()->items(
			self::package(
				array(
					array( 'quantity' => 1 ),
					array(
						'data'     => self::product(),
						'quantity' => 1,
					),
				)
			)
		);

		self::assertCount( 1, $items );
	}

	public function test_subtotal_is_read_from_the_package(): void {
		$package = self::package(
			array(
				array(
					'data'       => self::product(),
					'quantity'   => 2,
					'line_total' => 1500.0,
				),
				array(
					'data'       => self::product(),
					'quantity'   => 1,
					'line_total' => 1000.5,
				),
			)
		);

		$subtotal = $this->reader()->subtotal( $package, 'RUB' );

		self::assertSame( '2500.50', $subtotal->amount );
		self::assertSame( 'RUB', $subtotal->currency_code );
	}

	/**
	 * Налог входит в объявленную стоимость: покупатель платит именно её.
	 */
	public function test_subtotal_includes_line_tax(): void {
		$package = self::package(
			array(
				array(
					'data'       => self::product(),
					'quantity'   => 1,
					'line_total' => 1000.0,
					'line_tax'   => 200.0,
				),
			)
		);

		self::assertSame( '1200.00', $this->reader()->subtotal( $package, 'RUB' )->amount );
	}

	public function test_subtotal_of_an_empty_package_is_zero(): void {
		self::assertSame( 0, $this->reader()->subtotal( array(), 'RUB' )->minor_units() );
	}

	/**
	 * Единицы берутся из настроек WooCommerce, а не угадываются.
	 */
	public function test_other_units_are_honoured(): void {
		$reader = new PackageReader( 'g', 'mm' );

		$items = $reader->items(
			self::package(
				array(
					array(
						'data'     => self::product( '250', '300', '200', '100' ),
						'quantity' => 1,
					),
				)
			)
		);

		self::assertSame( 250, $items[0]->dimensions->weight_g );
		self::assertSame( 300, $items[0]->dimensions->length_mm );
	}
}
