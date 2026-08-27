<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Shipping;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Delivery;
use Spoki\OzonDelivery\Checkout\ClientCheck;
use Spoki\OzonDelivery\Checkout\SessionState;
use Spoki\OzonDelivery\Support\Logger;
use WC_Shipping_Method;


/**
 * Метод доставки «Ozon: пункт выдачи».
 *
 * Тонкая обвязка: вся логика расчёта живёт в QuoteBuilder, а причины отказа
 * складываются в сессию. Правило 5: ни одной wc_add_notice(..., 'error') —
 * это ловит check_cart_items() и валит весь чекаут. Нет тарифа — метод просто
 * не показывается, а покупатель видит объяснение отдельной строкой.
 *
 * Не юнит-тестируется: WC_Shipping_Method существует только при загруженном
 * WooCommerce. Проверяется в wp-env.
 */
final class MethodPickup extends WC_Shipping_Method {

	public const ID = Methods::PICKUP;

	/**
	 * @param int $instance_id Экземпляр в зоне доставки.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Ozon Доставка — пункт выдачи', 'ozon-delivery-for-woocommerce' );
		$this->method_description = __(
			'Доставка до пункта выдачи Ozon. Стоимость и срок приходят из Ozon по составу корзины и выбранной точке.',
			'ozon-delivery-for-woocommerce'
		);
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', __( 'Ozon: пункт выдачи', 'ozon-delivery-for-woocommerce' ) );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		// process_admin_options возвращает bool, а обработчик действия возвращать
		// ничего не должен — поэтому через замыкание.
		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'enabled'    => array(
				'title'   => __( 'Включить', 'ozon-delivery-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'title'      => array(
				'title'       => __( 'Название для покупателя', 'ozon-delivery-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Ozon: пункт выдачи', 'ozon-delivery-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Как метод называется на чекауте.', 'ozon-delivery-for-woocommerce' ),
			),
			'tax_status' => array(
				'title'   => __( 'Налог', 'ozon-delivery-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Облагается', 'ozon-delivery-for-woocommerce' ),
					'none'    => __( 'Не облагается', 'ozon-delivery-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $package Пакет доставки WooCommerce.
	 */
	public function calculate_shipping( $package = array() ): void {
		$state = new SessionState();

		$point_id = $state->chosen_point_id();

		if ( null === $point_id ) {
			$state->remember_notice(
				__( 'Выберите пункт выдачи Ozon, чтобы увидеть стоимость доставки.', 'ozon-delivery-for-woocommerce' )
			);

			return;
		}

		$phone = $this->customer_phone();

		if ( ! $this->client_check()->can_deliver_to( $phone ) ) {
			$state->remember_notice(
				__(
					'Доставка Ozon доступна покупателям, зарегистрированным на Ozon. Проверьте номер телефона.',
					'ozon-delivery-for-woocommerce'
				)
			);

			return;
		}

		$quote = QuoteBuilder::create()->quote( $package, $phone, Destination::point( $point_id ) );

		if ( ! $quote->available ) {
			// Причина уходит своей строкой, а не в wc_add_notice('error').
			$state->remember_notice( $quote->message );

			return;
		}

		$total = $quote->total();

		if ( null === $total ) {
			return;
		}

		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => $this->title,
				// Сумма уходит строкой: своей float-арифметики здесь нет.
				'cost'      => $total->amount,
				'package'   => $package,
				'meta_data' => array(
					__( 'Пункт выдачи', 'ozon-delivery-for-woocommerce' ) => $point_id,
					__( 'Срок, дней', 'ozon-delivery-for-woocommerce' )   => $quote->estimated_delivery_days,
				),
			)
		);
	}

	/**
	 * Телефон берётся из данных покупателя: во время чекаута WooCommerce
	 * обновляет их из отправленной формы.
	 */
	private function customer_phone(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		/** @var mixed $woocommerce */
		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) ) {
			return '';
		}

		$customer = $woocommerce->customer ?? null;

		if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_billing_phone' ) ) ) {
			return '';
		}

		return (string) $customer->get_billing_phone();
	}

	private function client_check(): ClientCheck {
		return new ClientCheck( new Delivery( ClientFactory::create() ), new Logger() );
	}
}
