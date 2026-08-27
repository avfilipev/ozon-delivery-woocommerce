<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Spoki\OzonDelivery\Order\ReturnStatus;
use Spoki\OzonDelivery\Tests\TestCase;

final class ReturnStatusTest extends TestCase {

	/**
	 * Все семь статусов возврата из docs/API.md.
	 *
	 * @dataProvider known_status_provider
	 */
	public function test_known_status_has_a_human_label( string $status ): void {
		$return_status = new ReturnStatus( $status );

		self::assertTrue( $return_status->is_known() );
		self::assertNotSame( '', $return_status->label() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function known_status_provider(): array {
		return array(
			'MOVING'               => array( 'MOVING' ),
			'AT_THE_PICK_UP_POINT' => array( 'AT_THE_PICK_UP_POINT' ),
			'RECEIVED'             => array( 'RECEIVED' ),
			'UTILIZATION'          => array( 'UTILIZATION' ),
			'UTILIZED'             => array( 'UTILIZED' ),
			'WRITTEN_OFF'          => array( 'WRITTEN_OFF' ),
			'LOOKING_FOR'          => array( 'LOOKING_FOR' ),
		);
	}

	public function test_unknown_status_is_reported_but_not_fatal(): void {
		$status = new ReturnStatus( 'SOMETHING_NEW' );

		self::assertFalse( $status->is_known() );
		self::assertStringContainsString( 'SOMETHING_NEW', $status->label() );
	}

	public function test_status_is_normalised(): void {
		self::assertSame( 'RECEIVED', ( new ReturnStatus( ' received ' ) )->value );
	}

	/**
	 * Возврат получен продавцом — товар снова у него.
	 */
	public function test_received_is_final(): void {
		self::assertTrue( ( new ReturnStatus( 'RECEIVED' ) )->is_final() );
	}

	/**
	 * Утилизированный и списанный товар назад уже не приедет.
	 *
	 * @dataProvider terminal_status_provider
	 */
	public function test_terminal_statuses_are_final( string $status ): void {
		self::assertTrue( ( new ReturnStatus( $status ) )->is_final() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function terminal_status_provider(): array {
		return array(
			'получен'      => array( 'RECEIVED' ),
			'утилизирован' => array( 'UTILIZED' ),
			'списан'       => array( 'WRITTEN_OFF' ),
		);
	}

	public function test_moving_is_not_final(): void {
		self::assertFalse( ( new ReturnStatus( 'MOVING' ) )->is_final() );
	}

	/**
	 * Разыскиваемый и отправленный на утилизацию возврат требует человека:
	 * деньги за товар магазин уже не вернёт сам собой.
	 *
	 * @dataProvider attention_status_provider
	 */
	public function test_statuses_needing_attention( string $status ): void {
		self::assertTrue( ( new ReturnStatus( $status ) )->needs_attention() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function attention_status_provider(): array {
		return array(
			'разыскивается' => array( 'LOOKING_FOR' ),
			'на утилизацию' => array( 'UTILIZATION' ),
			'утилизирован'  => array( 'UTILIZED' ),
			'списан'        => array( 'WRITTEN_OFF' ),
		);
	}

	public function test_normal_movement_needs_no_attention(): void {
		self::assertFalse( ( new ReturnStatus( 'MOVING' ) )->needs_attention() );
		self::assertFalse( ( new ReturnStatus( 'RECEIVED' ) )->needs_attention() );
	}

	public function test_is_waiting_at_the_point(): void {
		self::assertTrue( ( new ReturnStatus( 'AT_THE_PICK_UP_POINT' ) )->is_waiting_for_pickup() );
		self::assertFalse( ( new ReturnStatus( 'MOVING' ) )->is_waiting_for_pickup() );
	}
}
