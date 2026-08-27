<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Endpoints;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Order\CreatedOrder;
use Spoki\OzonDelivery\Shipping\CheckoutQuote;
use Spoki\OzonDelivery\Shipping\Destination;
use Spoki\OzonDelivery\Shipping\Dimensions;
use Spoki\OzonDelivery\Support\Money;

/**
 * Расчёт доставки и оформление заказа.
 *
 * @see docs/API.md, раздел «Расчёт и заказ»
 */
final class Orders {

	private const CHECKOUT_PATH = '/v1/order/checkout';

	private const CREATE_PATH = '/v1/order/create';

	/**
	 * В версии 1 отправление одно на заказ, поэтому request_id всегда 1: по
	 * нему Ozon сопоставляет результаты и ошибки в пределах запроса.
	 */
	private const REQUEST_ID = 1;

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * Предрасчёт: стоимость доставки, стоимость страховки, срок и cutoff.
	 *
	 * Метод ничего не создаёт, поэтому безопасен и на боевом контуре.
	 */
	public function checkout(
		string $phone_number,
		int $shipment_method_id,
		Dimensions $dimensions,
		Money $declared_value,
		Destination $destination,
		?string $cutoff_at = null
	): CheckoutQuote {
		$posting = array(
			'request_id'         => self::REQUEST_ID,
			'shipment_method_id' => $shipment_method_id,
			'declared_value'     => $declared_value->to_array(),
			'dimensions'         => $dimensions->to_array(),
		);

		if ( null !== $cutoff_at && '' !== $cutoff_at ) {
			$posting['cutoff_at'] = $cutoff_at;
		}

		/**
		 * Тело запроса предрасчёта.
		 *
		 * @param array<string, mixed> $payload Тело запроса.
		 */
		$payload = (array) apply_filters(
			'ozon_delivery_checkout_payload',
			array(
				'recipient' => array( 'phone_number' => trim( $phone_number ) ),
				'postings'  => array( $posting ),
				'delivery'  => $destination->to_array(),
			)
		);

		$response = $this->client->post( self::CHECKOUT_PATH, $payload );

		$results = isset( $response['results'] ) && is_array( $response['results'] )
			? $response['results']
			: array();

		foreach ( $results as $result ) {
			if ( is_array( $result ) && self::REQUEST_ID === (int) ( $result['request_id'] ?? 0 ) ) {
				return CheckoutQuote::from_result( $result );
			}
		}

		// Пустой results — не успех: расчёта нет, показывать нечего.
		return CheckoutQuote::failed( '', 'Ozon не вернул расчёт доставки.' );
	}

	/**
	 * Создаёт заказ в Ozon.
	 *
	 * Метод создаёт реальные сущности и списывает деньги. Ключ идемпотентности
	 * обязателен (правило 4) и должен быть одним и тем же для всех попыток
	 * передать этот заказ — иначе появится второе отправление.
	 *
	 * @throws ApiException Курьерская доставка, пустой ключ или отказ Ozon.
	 */
	public function create(
		string $order_external_id,
		string $phone_number,
		string $full_name,
		int $shipment_method_id,
		string $description,
		Dimensions $dimensions,
		Money $declared_value,
		Destination $destination,
		string $idempotency_key,
		?string $cutoff_at = null
	): CreatedOrder {
		if ( ! $destination->is_pickup_point() ) {
			// Курьеру нужен полный адрес (zip_code, country, region, city,
			// street и далее). В версии 1 его неоткуда взять, а слать заведомо
			// неполный запрос в боевой контур нельзя.
			throw new ApiException(
				'Передача заказа курьером пока не поддерживается: нужен полный адрес получателя.'
			);
		}

		$posting = array(
			'request_id'          => self::REQUEST_ID,
			'posting_external_id' => $order_external_id,
			'shipment_method_id'  => $shipment_method_id,
			'description'         => $description,
			'declared_value'      => $declared_value->to_array(),
			'dimensions'          => $dimensions->to_array(),
		);

		if ( null !== $cutoff_at && '' !== $cutoff_at ) {
			$posting['cutoff_at'] = $cutoff_at;
		}

		$recipient = array( 'phone_number' => trim( $phone_number ) );

		if ( '' !== trim( $full_name ) ) {
			$recipient['full_name'] = trim( $full_name );
		}

		/**
		 * Тело запроса создания заказа.
		 *
		 * @param array<string, mixed> $payload Тело запроса.
		 */
		$payload = (array) apply_filters(
			'ozon_delivery_create_payload',
			array(
				'order_external_id' => $order_external_id,
				'recipient'         => $recipient,
				'postings'          => array( $posting ),
				'delivery'          => $destination->to_array(),
			)
		);

		return CreatedOrder::from_response(
			$this->client->post( self::CREATE_PATH, $payload, array( 'Idempotency-Key' => $idempotency_key ) )
		);
	}
}
