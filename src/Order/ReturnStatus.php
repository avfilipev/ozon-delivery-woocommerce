<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Статус возврата Ozon.
 *
 * Отдельная модель статусов: у возвратов свой набор, не пересекающийся с
 * прямым потоком отправлений.
 *
 * @see docs/API.md, раздел «Статусы» → «Возврат»
 */
final class ReturnStatus {

	/**
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'MOVING'               => 'Едет к продавцу',
		'AT_THE_PICK_UP_POINT' => 'Ждёт в пункте выдачи',
		'RECEIVED'             => 'Получен продавцом',
		'UTILIZATION'          => 'Отправлен на утилизацию',
		'UTILIZED'             => 'Утилизирован',
		'WRITTEN_OFF'          => 'Списан',
		'LOOKING_FOR'          => 'Разыскивается',
	);

	/**
	 * Дальше движения не будет: товар либо вернулся, либо не вернётся уже
	 * никогда.
	 *
	 * @var string[]
	 */
	private const FINAL_STATUSES = array( 'RECEIVED', 'UTILIZED', 'WRITTEN_OFF' );

	/**
	 * Требуют человека: товар потерян, утилизируется или списан — деньги за
	 * него сами собой не вернутся.
	 *
	 * @var string[]
	 */
	private const ATTENTION_STATUSES = array( 'LOOKING_FOR', 'UTILIZATION', 'UTILIZED', 'WRITTEN_OFF' );

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
			return 'Статус возврата неизвестен';
		}

		return sprintf( 'Неизвестный статус возврата Ozon: %s', $this->value );
	}

	public function is_final(): bool {
		return in_array( $this->value, self::FINAL_STATUSES, true );
	}

	public function needs_attention(): bool {
		return in_array( $this->value, self::ATTENTION_STATUSES, true );
	}

	public function is_waiting_for_pickup(): bool {
		return 'AT_THE_PICK_UP_POINT' === $this->value;
	}
}
