<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use InvalidArgumentException;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Tests\TestCase;

final class DestinationTest extends TestCase {

	public function test_pickup_point_destination_matches_the_spec(): void {
		$destination = Destination::point( 4242 );

		self::assertSame(
			array( 'delivery_point' => array( 'delivery_point_id' => 4242 ) ),
			$destination->to_array()
		);
	}

	public function test_courier_destination_matches_the_spec(): void {
		$destination = Destination::courier( 55.757, 37.615 );

		self::assertSame(
			array(
				'courier' => array(
					'coordinates' => array(
						'latitude'  => 55.757,
						'longitude' => 37.615,
					),
				),
			),
			$destination->to_array()
		);
	}

	public function test_pickup_point_is_recognised(): void {
		self::assertTrue( Destination::point( 1 )->is_pickup_point() );
		self::assertFalse( Destination::courier( 55.0, 37.0 )->is_pickup_point() );
	}

	public function test_pickup_point_id_is_exposed(): void {
		self::assertSame( 4242, Destination::point( 4242 )->delivery_point_id );
		self::assertNull( Destination::courier( 55.0, 37.0 )->delivery_point_id );
	}

	public function test_zero_point_id_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Destination::point( 0 );
	}

	/**
	 * @dataProvider out_of_range_provider
	 */
	public function test_impossible_coordinates_are_refused( float $latitude, float $longitude ): void {
		$this->expectException( InvalidArgumentException::class );

		Destination::courier( $latitude, $longitude );
	}

	/**
	 * @return array<string, array{0: float, 1: float}>
	 */
	public static function out_of_range_provider(): array {
		return array(
			'широта больше 90'    => array( 91.0, 37.0 ),
			'широта меньше -90'   => array( -91.0, 37.0 ),
			'долгота больше 180'  => array( 55.0, 181.0 ),
			'долгота меньше -180' => array( 55.0, -181.0 ),
		);
	}

	public function test_boundary_coordinates_are_accepted(): void {
		self::assertSame(
			array(
				'latitude'  => 90.0,
				'longitude' => 180.0,
			),
			Destination::courier( 90.0, 180.0 )->to_array()['courier']['coordinates']
		);
	}
}
