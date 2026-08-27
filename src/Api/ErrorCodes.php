<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

/**
 * Коды ошибок, приходящие внутри 200-го ответа.
 *
 * Правило 3: у order/checkout, order/create, delivery/location и
 * delivery-point/check-availability HTTP 200 успехом не является — ошибки
 * лежат поэлементно в results[].error.
 *
 * @see docs/API.md, раздел «Коды ошибок внутри 200»
 */
final class ErrorCodes {

	/**
	 * @var array<string, string>
	 */
	private const MESSAGES = array(
		'DPRE' => 'Пункт выдачи не подходит под параметры заказа.',
		'SPE'  => 'Пункт выдачи совпадает с точкой отгрузки.',
		'SDPE' => 'Точка доставки совпадает с точкой отгрузки.',
		'DPNF' => 'Не найдена информация о пункте выдачи.',
		'DAE'  => 'Не удалось рассчитать доставку до пункта выдачи.',
		'DCE'  => 'Не удалось предрассчитать стоимость доставки или страховки.',
		'RE'   => 'Невозможно доставить заказ получателю.',
		'PE'   => 'Кабинет продавца заблокирован.',
		'NEB'  => 'Недостаточно средств на балансе кабинета Ozon.',
		'PNF'  => 'Отправление не найдено.',
		'OE'   => 'Ozon не смог выполнить расчёт.',
	);

	/**
	 * Коды, означающие «не подходит именно эта точка»: такие точки убираются
	 * из выдачи, остальные показываются как обычно.
	 *
	 * @var string[]
	 */
	private const POINT_SPECIFIC = array( 'DPRE', 'SPE', 'SDPE', 'DPNF', 'DAE' );

	/**
	 * Человеческое сообщение по коду.
	 *
	 * @param string $code    Код из results[].error.code.
	 * @param string $message Текст от Ozon, если он пришёл: он точнее словаря.
	 */
	public static function message( string $code, string $message = '' ): string {
		$code = strtoupper( trim( $code ) );

		if ( '' !== trim( $message ) ) {
			return trim( $message );
		}

		if ( isset( self::MESSAGES[ $code ] ) ) {
			return self::MESSAGES[ $code ];
		}

		if ( '' === $code ) {
			return 'Ozon вернул ошибку без кода.';
		}

		return sprintf( 'Ozon вернул неизвестную ошибку с кодом %s.', $code );
	}

	/**
	 * Нужен человек: пополнить баланс, иначе отгружать нельзя.
	 */
	public static function is_balance_problem( string $code ): bool {
		return 'NEB' === strtoupper( trim( $code ) );
	}

	/**
	 * Нужен человек: кабинет заблокирован, повторять запрос бессмысленно.
	 */
	public static function is_account_problem( string $code ): bool {
		return 'PE' === strtoupper( trim( $code ) );
	}

	public static function is_point_specific( string $code ): bool {
		return in_array( strtoupper( trim( $code ) ), self::POINT_SPECIFIC, true );
	}
}
