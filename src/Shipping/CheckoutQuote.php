<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Api\ErrorCodes;
use Spoki\OzonDelivery\Support\Money;

/**
 * Предрасчёт доставки по одному отправлению из order/checkout.
 *
 * @see docs/API.md, метод order/checkout
 */
final class CheckoutQuote {

	public function __construct(
		public readonly bool $available,
		public readonly ?Money $delivery_cost = null,
		public readonly ?Money $insurance_cost = null,
		public readonly ?int $estimated_delivery_days = null,
		public readonly ?string $cutoff_at = null,
		public readonly string $error_code = '',
		public readonly string $message = ''
	) {
	}

	/**
	 * Что показать покупателю, когда расчёта нет.
	 *
	 * Часть отказов относится к выбранной точке, а не к заказу: другой пункт
	 * выдачи всё исправит. Отличать такие коды плагин умел, но покупателю не
	 * говорил — тот читал «пункт выдачи не подходит» и не понимал, что
	 * делать. Заблокированный кабинет или пустой баланс сменой точки не
	 * лечатся, и гонять покупателя по списку там незачем.
	 */
	public function customer_message(): string {
		if ( $this->available ) {
			return '';
		}

		if ( ! ErrorCodes::is_point_specific( $this->error_code ) ) {
			return $this->message;
		}

		return trim(
			$this->message . ' ' . __( 'Выберите другой пункт выдачи.', 'ozon-delivery-for-woocommerce' )
		);
	}

	/**
	 * @param array<string, mixed> $result Элемент results[] из ответа Ozon.
	 */
	public static function from_result( array $result ): self {
		$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();

		if ( array() !== $error ) {
			$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
			$message = isset( $error['message'] ) ? (string) $error['message'] : '';

			return self::failed( $code, ErrorCodes::message( $code, $message ) );
		}

		$posting = isset( $result['posting'] ) && is_array( $result['posting'] ) ? $result['posting'] : array();

		if ( array() === $posting ) {
			return self::failed( '', 'Ozon не вернул расчёт по этому отправлению.' );
		}

		$cutoff = isset( $posting['cutoff_at'] ) ? (string) $posting['cutoff_at'] : '';

		return new self(
			true,
			self::money( $posting['estimated_delivery_cost'] ?? null ),
			self::money( $posting['estimated_insurance_cost'] ?? null ),
			isset( $posting['estimated_delivery_days'] ) && is_numeric( $posting['estimated_delivery_days'] )
				? (int) $posting['estimated_delivery_days']
				: null,
			'' === $cutoff ? null : $cutoff
		);
	}

	public static function failed( string $error_code, string $message ): self {
		return new self( false, null, null, null, null, $error_code, $message );
	}

	/**
	 * Доставка плюс страховка. Складывается в минорных единицах — правило 9.
	 */
	public function total(): ?Money {
		if ( null === $this->delivery_cost ) {
			return null;
		}

		if ( null === $this->insurance_cost ) {
			return $this->delivery_cost;
		}

		return $this->delivery_cost->add( $this->insurance_cost );
	}

	private static function money( mixed $value ): ?Money {
		return is_array( $value ) && isset( $value['amount'] ) ? Money::from_array( $value ) : null;
	}
}
