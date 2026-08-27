<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Support\Money;

/**
 * Объявленная стоимость отправления.
 *
 * Влияет сразу на три вещи: на страховку, на её цену и на доступность
 * пунктов выдачи (у точек есть min_price и max_price). Поэтому это отдельное
 * решение с настройкой и фильтром, а не просто сумма заказа.
 *
 * Считается в минорных единицах целыми числами — правило 9.
 */
final class DeclaredValue {

	/**
	 * Объявлять больше суммы заказа — почти всегда опечатка в настройке
	 * (например, 10000 вместо 100). Осознанный случай закрывается фильтром
	 * ozon_delivery_declared_value.
	 */
	private const MAX_PERCENT = 100;

	private readonly int $percent;

	public function __construct( int $percent = 100 ) {
		$this->percent = max( 0, min( self::MAX_PERCENT, $percent ) );
	}

	public function for_subtotal( Money $subtotal ): Money {
		$minor = intdiv( $subtotal->minor_units() * $this->percent, 100 );

		$value = Money::from_minor_units( $minor, $subtotal->currency_code );

		/**
		 * Объявленная стоимость отправления.
		 *
		 * @param Money $value    Рассчитанная стоимость.
		 * @param Money $subtotal Сумма заказа, от которой считали.
		 */
		/** @var mixed $filtered Фильтр может вернуть что угодно: сниппет пишет третья сторона. */
		$filtered = apply_filters( 'ozon_delivery_declared_value', $value, $subtotal );

		return $filtered instanceof Money ? $filtered : $value;
	}
}
