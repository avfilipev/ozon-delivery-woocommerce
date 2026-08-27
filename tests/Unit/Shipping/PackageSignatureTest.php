<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Shipping\PackageSignature;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

final class PackageSignatureTest extends TestCase {

	private static function signature(
		?Dimensions $dimensions = null,
		?Money $declared_value = null,
		?Destination $destination = null,
		int $shipment_method_id = 777,
		string $phone = '+79000000000'
	): string {
		return PackageSignature::create(
			$phone,
			$shipment_method_id,
			$dimensions ?? new Dimensions( 1200, 300, 200, 100 ),
			$declared_value ?? new Money( '2500.00', 'RUB' ),
			$destination ?? Destination::point( 4242 )
		);
	}

	public function test_signature_is_stable_for_identical_input(): void {
		self::assertSame( self::signature(), self::signature() );
	}

	public function test_signature_is_not_empty(): void {
		self::assertNotSame( '', self::signature() );
	}

	/**
	 * Кэш не должен переживать изменение корзины: иначе покупатель увидит
	 * цену от прошлого состава заказа.
	 */
	public function test_different_dimensions_give_different_signatures(): void {
		self::assertNotSame(
			self::signature(),
			self::signature( new Dimensions( 9999, 300, 200, 100 ) )
		);
	}

	public function test_different_declared_value_gives_different_signature(): void {
		self::assertNotSame(
			self::signature(),
			self::signature( null, new Money( '9999.00', 'RUB' ) )
		);
	}

	public function test_different_point_gives_different_signature(): void {
		self::assertNotSame(
			self::signature(),
			self::signature( null, null, Destination::point( 5555 ) )
		);
	}

	public function test_different_shipment_method_gives_different_signature(): void {
		self::assertNotSame( self::signature(), self::signature( null, null, null, 888 ) );
	}

	public function test_different_phone_gives_different_signature(): void {
		self::assertNotSame( self::signature(), self::signature( null, null, null, 777, '+79111111111' ) );
	}

	public function test_courier_and_pickup_differ(): void {
		self::assertNotSame(
			self::signature( null, null, Destination::point( 4242 ) ),
			self::signature( null, null, Destination::courier( 55.757, 37.615 ) )
		);
	}

	/**
	 * Подпись уходит в ключ транзиента, а он ограничен по длине.
	 */
	public function test_signature_is_short_enough_for_a_transient_key(): void {
		self::assertLessThanOrEqual( 40, strlen( self::signature() ) );
	}

	/**
	 * Телефон покупателя — персональные данные, в ключе кэша его быть не должно.
	 */
	public function test_signature_does_not_leak_the_phone_number(): void {
		self::assertStringNotContainsString( '79000000000', self::signature() );
	}
}
