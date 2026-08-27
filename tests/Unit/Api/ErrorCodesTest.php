<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Api;

use Spoki\OzonDelivery\Api\ErrorCodes;
use Spoki\OzonDelivery\Tests\TestCase;

final class ErrorCodesTest extends TestCase {

	/**
	 * Все коды из docs/API.md, раздел «Коды ошибок внутри 200».
	 *
	 * @dataProvider known_code_provider
	 */
	public function test_known_code_has_a_human_message( string $code ): void {
		$message = ErrorCodes::message( $code );

		self::assertNotSame( '', $message );
		self::assertStringNotContainsString( $code, $message, 'Сообщение не должно быть просто кодом.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function known_code_provider(): array {
		return array(
			'DPRE' => array( 'DPRE' ),
			'SPE'  => array( 'SPE' ),
			'SDPE' => array( 'SDPE' ),
			'DPNF' => array( 'DPNF' ),
			'DAE'  => array( 'DAE' ),
			'DCE'  => array( 'DCE' ),
			'RE'   => array( 'RE' ),
			'PE'   => array( 'PE' ),
			'NEB'  => array( 'NEB' ),
			'PNF'  => array( 'PNF' ),
			'OE'   => array( 'OE' ),
		);
	}

	public function test_unknown_code_falls_back_to_a_readable_message(): void {
		$message = ErrorCodes::message( 'WAT' );

		self::assertNotSame( '', $message );
		self::assertStringContainsString( 'WAT', $message );
	}

	public function test_empty_code_is_handled(): void {
		self::assertNotSame( '', ErrorCodes::message( '' ) );
	}

	public function test_message_from_ozon_is_preferred_over_the_dictionary(): void {
		$message = ErrorCodes::message( 'OE', 'Своя формулировка от Ozon' );

		self::assertStringContainsString( 'Своя формулировка от Ozon', $message );
	}

	/**
	 * Недостаточный баланс — не ошибка запроса, а повод показать админу
	 * отдельное предупреждение.
	 */
	public function test_insufficient_balance_is_recognised(): void {
		self::assertTrue( ErrorCodes::is_balance_problem( 'NEB' ) );
		self::assertFalse( ErrorCodes::is_balance_problem( 'OE' ) );
	}

	/**
	 * Заблокированный кабинет продавца тоже требует вмешательства человека,
	 * а не повторной попытки.
	 */
	public function test_blocked_seller_account_is_recognised(): void {
		self::assertTrue( ErrorCodes::is_account_problem( 'PE' ) );
		self::assertFalse( ErrorCodes::is_account_problem( 'DAE' ) );
	}

	/**
	 * Часть кодов означает «эта точка не подходит», а не «всё сломалось»:
	 * такие точки просто убираются из выдачи.
	 *
	 * @dataProvider point_specific_code_provider
	 */
	public function test_point_specific_codes_are_recognised( string $code ): void {
		self::assertTrue( ErrorCodes::is_point_specific( $code ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function point_specific_code_provider(): array {
		return array(
			'DPRE' => array( 'DPRE' ),
			'SPE'  => array( 'SPE' ),
			'SDPE' => array( 'SDPE' ),
			'DPNF' => array( 'DPNF' ),
			'DAE'  => array( 'DAE' ),
		);
	}

	public function test_general_failure_is_not_point_specific(): void {
		self::assertFalse( ErrorCodes::is_point_specific( 'PE' ) );
		self::assertFalse( ErrorCodes::is_point_specific( 'OE' ) );
	}
}
