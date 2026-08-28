<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Spoki\OzonDelivery\Shipping\CheckoutQuote;
use Spoki\OzonDelivery\Support\Money;
use Spoki\OzonDelivery\Tests\TestCase;

/**
 * Сообщение покупателю о неудавшемся расчёте.
 *
 * Часть отказов относится к выбранной точке, а не к заказу: другой пункт
 * выдачи всё исправит. Отличать их плагин умел (`ErrorCodes::POINT_SPECIFIC`),
 * но покупателю об этом не говорил — тот видел «пункт выдачи не подходит» и
 * не понимал, что делать дальше.
 */
final class CheckoutQuoteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_point_specific_failure_suggests_another_point(): void {
		$quote = CheckoutQuote::failed( 'DPRE', 'Пункт выдачи не подходит под параметры заказа.' );

		self::assertStringContainsString( 'Пункт выдачи не подходит', $quote->customer_message() );
		self::assertStringContainsString( 'другой', mb_strtolower( $quote->customer_message() ) );
	}

	/**
	 * Заблокированный кабинет продавца или пустой баланс сменой точки не
	 * лечится — предлагать это значит гонять покупателя впустую.
	 */
	public function test_shop_side_failure_is_left_as_is(): void {
		$quote = CheckoutQuote::failed( 'NEB', 'Недостаточно средств на балансе кабинета Ozon.' );

		self::assertSame( 'Недостаточно средств на балансе кабинета Ozon.', $quote->customer_message() );
	}

	public function test_successful_quote_says_nothing(): void {
		$quote = new CheckoutQuote( true, new Money( '158.00', 'RUB' ) );

		self::assertSame( '', $quote->customer_message() );
	}
}
