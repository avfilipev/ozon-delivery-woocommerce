<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Checkout;

use Brain\Monkey\Functions;
use Mockery;
use Spoki\OzonDelivery\Checkout\SessionState;
use Spoki\OzonDelivery\Tests\TestCase;

final class SessionStateTest extends TestCase {

	/**
	 * @var array<string, mixed>
	 */
	private array $session = array();

	private bool $session_available = true;

	protected function setUp(): void {
		parent::setUp();

		$this->session           = array();
		$this->session_available = true;

		$session = Mockery::mock();
		$session->shouldReceive( 'get' )->andReturnUsing(
			fn( string $key, $default_value = null ) => $this->session[ $key ] ?? $default_value
		);
		$session->shouldReceive( 'set' )->andReturnUsing(
			function ( string $key, $value ): void {
				$this->session[ $key ] = $value;
			}
		);

		$woocommerce          = Mockery::mock();
		$woocommerce->session = $session;

		Functions\when( 'WC' )->alias(
			fn() => $this->session_available ? $woocommerce : null
		);
	}

	public function test_no_point_is_chosen_initially(): void {
		self::assertNull( ( new SessionState() )->chosen_point_id() );
	}

	public function test_chosen_point_is_remembered(): void {
		$state = new SessionState();

		$state->choose_point( 4242 );

		self::assertSame( 4242, $state->chosen_point_id() );
	}

	public function test_choice_can_be_cleared(): void {
		$state = new SessionState();
		$state->choose_point( 4242 );

		$state->forget_point();

		self::assertNull( $state->chosen_point_id() );
	}

	public function test_zero_point_is_not_a_choice(): void {
		$state = new SessionState();

		$state->choose_point( 0 );

		self::assertNull( $state->chosen_point_id() );
	}

	/**
	 * Правило 5: ошибка расчёта не идёт через wc_add_notice('error'), иначе
	 * её поймает check_cart_items() и уронит весь чекаут. Она живёт здесь.
	 */
	public function test_notice_is_remembered(): void {
		$state = new SessionState();

		$state->remember_notice( 'Не удалось рассчитать доставку.' );

		self::assertSame( 'Не удалось рассчитать доставку.', $state->current_notice() );
	}

	/**
	 * Сообщение обязано пережить перерисовку чекаута.
	 *
	 * Раньше оно забиралось один раз — и это молча ломало главный сценарий.
	 * WooCommerce кэширует тарифы по отпечатку пакета: пока покупатель не
	 * менял ни точку, ни телефон, расчёт повторно не запускается и сообщение
	 * заново не появляется. Покупатель видел объяснение один раз, а дальше
	 * оставался с пустой строкой доставки и без единой подсказки.
	 *
	 * Причина отпала — сообщение снимает тот, кто её устранил.
	 */
	public function test_notice_survives_repeated_rendering(): void {
		$state = new SessionState();

		$state->remember_notice( 'Выберите пункт выдачи.' );

		self::assertSame( 'Выберите пункт выдачи.', $state->current_notice() );
		self::assertSame( 'Выберите пункт выдачи.', $state->current_notice() );

		$state->forget_notice();

		self::assertNull( $state->current_notice() );
	}

	public function test_no_notice_by_default(): void {
		self::assertNull( ( new SessionState() )->current_notice() );
	}

	public function test_empty_notice_is_not_stored(): void {
		$state = new SessionState();

		$state->remember_notice( '   ' );

		self::assertNull( $state->current_notice() );
	}

	/**
	 * Сессии нет в админке, в WP-CLI и в REST-запросах: обращение к ней не
	 * должно ронять эти сценарии.
	 */
	public function test_missing_session_is_survivable(): void {
		$this->session_available = false;

		$state = new SessionState();

		$state->choose_point( 4242 );
		$state->remember_notice( 'что-то' );

		self::assertNull( $state->chosen_point_id() );
		self::assertNull( $state->current_notice() );
	}
}
