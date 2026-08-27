<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Статус отправления Ozon и его отражение в заказе WooCommerce.
 *
 * Вебхуков у Ozon нет, статусы приходят только опросом, поэтому здесь же
 * живут ответы на вопросы «можно ли ещё подтвердить», «доступна ли этикетка»
 * и «нужно ли вмешательство человека».
 *
 * @see docs/API.md, раздел «Статусы»
 */
final class PostingStatus {

	/**
	 * Все девять статусов прямого потока из спецификации.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'CREATED'            => 'Создано, не подтверждено',
		'FORMING'            => 'Подтверждается',
		'FORMING_FAILED'     => 'Ошибка подтверждения',
		'READY_FOR_SHIPPING' => 'Готово к отгрузке',
		'ON_WAY'             => 'Едет к получателю',
		'IN_DELIVERY_POINT'  => 'В пункте выдачи',
		'IN_COURIER_SERVICE' => 'Передано курьеру',
		'DELIVERED'          => 'Выдано получателю',
		'CANCELED'           => 'Отменено',
	);

	/**
	 * Во что превращается статус заказа WooCommerce. null — не трогаем:
	 * ранние статусы ничего не говорят о судьбе заказа.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_STATUSES = array(
		'FORMING_FAILED'     => 'on-hold',
		'READY_FOR_SHIPPING' => 'processing',
		'ON_WAY'             => 'processing',
		'IN_DELIVERY_POINT'  => 'processing',
		'IN_COURIER_SERVICE' => 'processing',
		'DELIVERED'          => 'completed',
		'CANCELED'           => 'cancelled',
	);

	public readonly string $value;

	public function __construct( string $status ) {
		$this->value = strtoupper( trim( $status ) );
	}

	public function is_known(): bool {
		return isset( self::LABELS[ $this->value ] );
	}

	public function label(): string {
		if ( $this->is_known() ) {
			return self::LABELS[ $this->value ];
		}

		if ( '' === $this->value ) {
			return 'Статус неизвестен';
		}

		return sprintf( 'Неизвестный статус Ozon: %s', $this->value );
	}

	public function is_delivered(): bool {
		return 'DELIVERED' === $this->value;
	}

	public function is_canceled(): bool {
		return 'CANCELED' === $this->value;
	}

	/**
	 * Дальше статус не изменится — опрашивать это отправление больше не нужно.
	 */
	public function is_final(): bool {
		return $this->is_delivered() || $this->is_canceled();
	}

	/**
	 * Этикетка доступна только в READY_FOR_SHIPPING и обязательна для приёмки.
	 */
	public function label_available(): bool {
		return 'READY_FOR_SHIPPING' === $this->value;
	}

	public function can_be_approved(): bool {
		return 'CREATED' === $this->value;
	}

	/**
	 * Отмена недоступна после вручения.
	 */
	public function can_be_canceled(): bool {
		return $this->is_known() && ! $this->is_final();
	}

	/**
	 * Требует человека: повтор запроса тут не поможет.
	 */
	public function needs_attention(): bool {
		return 'FORMING_FAILED' === $this->value;
	}

	/**
	 * @return string|null Статус заказа WooCommerce или null, если менять нечего.
	 */
	public function to_order_status(): ?string {
		$status = self::ORDER_STATUSES[ $this->value ] ?? null;

		/**
		 * Во что превращать статус отправления Ozon.
		 *
		 * @param string|null $status Статус заказа WooCommerce или null.
		 * @param string      $posting_status Статус отправления Ozon.
		 */
		$filtered = apply_filters( 'ozon_delivery_order_status', $status, $this->value );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $status;
	}
}
