<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Order;

use Spoki\OzonDelivery\Order\PostingStatus;
use Spoki\OzonDelivery\Tests\TestCase;

final class PostingStatusTest extends TestCase {

	/**
	 * Все девять статусов отправления из docs/API.md должны быть известны.
	 *
	 * @dataProvider known_status_provider
	 */
	public function test_known_status_has_a_human_label( string $status ): void {
		$posting_status = new PostingStatus( $status );

		self::assertTrue( $posting_status->is_known() );
		self::assertNotSame( '', $posting_status->label() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function known_status_provider(): array {
		return array(
			'CREATED'            => array( 'CREATED' ),
			'FORMING'            => array( 'FORMING' ),
			'FORMING_FAILED'     => array( 'FORMING_FAILED' ),
			'READY_FOR_SHIPPING' => array( 'READY_FOR_SHIPPING' ),
			'ON_WAY'             => array( 'ON_WAY' ),
			'IN_DELIVERY_POINT'  => array( 'IN_DELIVERY_POINT' ),
			'IN_COURIER_SERVICE' => array( 'IN_COURIER_SERVICE' ),
			'DELIVERED'          => array( 'DELIVERED' ),
			'CANCELED'           => array( 'CANCELED' ),
		);
	}

	public function test_unknown_status_is_reported_but_not_fatal(): void {
		$status = new PostingStatus( 'SOMETHING_NEW' );

		self::assertFalse( $status->is_known() );
		self::assertStringContainsString( 'SOMETHING_NEW', $status->label() );
	}

	public function test_status_is_case_insensitive(): void {
		self::assertTrue( ( new PostingStatus( 'delivered' ) )->is_delivered() );
	}

	public function test_delivered_is_final(): void {
		$status = new PostingStatus( 'DELIVERED' );

		self::assertTrue( $status->is_delivered() );
		self::assertTrue( $status->is_final() );
	}

	public function test_canceled_is_final(): void {
		$status = new PostingStatus( 'CANCELED' );

		self::assertTrue( $status->is_canceled() );
		self::assertTrue( $status->is_final() );
	}

	public function test_in_progress_status_is_not_final(): void {
		self::assertFalse( ( new PostingStatus( 'ON_WAY' ) )->is_final() );
	}

	/**
	 * Отгружать можно только подтверждённое отправление, и только оно даёт
	 * этикетку.
	 */
	public function test_only_ready_for_shipping_allows_a_label(): void {
		self::assertTrue( ( new PostingStatus( 'READY_FOR_SHIPPING' ) )->label_available() );
		self::assertFalse( ( new PostingStatus( 'CREATED' ) )->label_available() );
		self::assertFalse( ( new PostingStatus( 'DELIVERED' ) )->label_available() );
	}

	/**
	 * Подтверждать имеет смысл только созданное отправление.
	 */
	public function test_only_created_can_be_approved(): void {
		self::assertTrue( ( new PostingStatus( 'CREATED' ) )->can_be_approved() );
		self::assertFalse( ( new PostingStatus( 'READY_FOR_SHIPPING' ) )->can_be_approved() );
		self::assertFalse( ( new PostingStatus( 'DELIVERED' ) )->can_be_approved() );
	}

	/**
	 * Отмена недоступна после вручения.
	 */
	public function test_delivered_cannot_be_cancelled(): void {
		self::assertFalse( ( new PostingStatus( 'DELIVERED' ) )->can_be_canceled() );
		self::assertFalse( ( new PostingStatus( 'CANCELED' ) )->can_be_canceled() );
		self::assertTrue( ( new PostingStatus( 'ON_WAY' ) )->can_be_canceled() );
	}

	/**
	 * Ошибка подтверждения требует внимания человека, а не повтора.
	 */
	public function test_forming_failed_needs_attention(): void {
		self::assertTrue( ( new PostingStatus( 'FORMING_FAILED' ) )->needs_attention() );
		self::assertFalse( ( new PostingStatus( 'ON_WAY' ) )->needs_attention() );
	}

	/**
	 * @dataProvider order_status_provider
	 */
	public function test_status_maps_to_a_woocommerce_order_status( string $status, ?string $expected ): void {
		self::assertSame( $expected, ( new PostingStatus( $status ) )->to_order_status() );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function order_status_provider(): array {
		return array(
			'вручено'         => array( 'DELIVERED', 'completed' ),
			'отменено'        => array( 'CANCELED', 'cancelled' ),
			'ошибка сборки'   => array( 'FORMING_FAILED', 'on-hold' ),
			'в пути'          => array( 'ON_WAY', 'processing' ),
			'в пункте выдачи' => array( 'IN_DELIVERY_POINT', 'processing' ),
			'создано'         => array( 'CREATED', null ),
			'неизвестный'     => array( 'SOMETHING_NEW', null ),
		);
	}

	public function test_value_is_normalised_to_upper_case(): void {
		self::assertSame( 'DELIVERED', ( new PostingStatus( ' delivered ' ) )->value );
	}

	public function test_empty_status_is_not_known(): void {
		self::assertFalse( ( new PostingStatus( '' ) )->is_known() );
	}
}
