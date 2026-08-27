<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Endpoints;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Order\Label;
use Spoki\OzonDelivery\Order\PostingActionResult;
use Spoki\OzonDelivery\Order\PostingInfo;
use Spoki\OzonDelivery\Order\StatusChange;

/**
 * Отправления: подтверждение, этикетка, статус, история, отмена.
 *
 * @see docs/API.md, раздел «Отправления»
 */
final class Postings {

	private const APPROVE_PATH = '/v1/posting/approve';

	private const LABEL_PATH = '/v1/posting/label';

	private const INFO_PATH = '/v1/posting/info';

	private const HISTORY_PATH = '/v1/posting/status-history';

	private const CANCEL_PATH = '/v1/posting/cancel';

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * Подтверждает отправление к отгрузке.
	 *
	 * Ozon проверяет баланс кабинета: без денег отгружать нельзя. Настоящий
	 * результат смотрится через info() — так велит документация.
	 */
	public function approve( string $posting_number ): PostingActionResult {
		return PostingActionResult::from_response(
			$this->client->post( self::APPROVE_PATH, array( 'posting_number' => $posting_number ) )
		);
	}

	public function cancel( string $posting_number ): PostingActionResult {
		return PostingActionResult::from_response(
			$this->client->post( self::CANCEL_PATH, array( 'posting_number' => $posting_number ) )
		);
	}

	/**
	 * @param string[] $posting_numbers
	 *
	 * @return array<string, PostingInfo> Ключ — posting_number.
	 */
	public function info( array $posting_numbers ): array {
		$numbers = array_values( array_filter( array_map( 'strval', $posting_numbers ) ) );

		if ( array() === $numbers ) {
			return array();
		}

		$response = $this->client->post( self::INFO_PATH, array( 'posting_numbers' => $numbers ) );

		$raw = isset( $response['postings'] ) && is_array( $response['postings'] )
			? $response['postings']
			: array();

		$postings = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$posting = PostingInfo::from_response( $item );

			if ( '' !== $posting->posting_number ) {
				$postings[ $posting->posting_number ] = $posting;
			}
		}

		return $postings;
	}

	/**
	 * @return StatusChange[]
	 */
	public function status_history( string $posting_number ): array {
		$response = $this->client->post( self::HISTORY_PATH, array( 'posting_number' => $posting_number ) );

		$raw = isset( $response['history'] ) && is_array( $response['history'] )
			? $response['history']
			: array();

		$history = array();

		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$history[] = StatusChange::from_response( $item );
			}
		}

		return $history;
	}

	/**
	 * Этикетка. Обязательна для приёмки и доступна только в статусе
	 * READY_FOR_SHIPPING.
	 *
	 * Ответ не разбирается как JSON: судя по спецификации, это файл.
	 *
	 * @throws ApiException Ozon вернул пустой ответ.
	 */
	public function label( string $posting_number ): Label {
		$response = $this->client->post_raw( self::LABEL_PATH, array( 'posting_number' => $posting_number ) );

		if ( '' === $response->body ) {
			throw new ApiException(
				sprintf( 'Ozon вернул пустую этикетку для отправления %s.', $posting_number )
			);
		}

		$content_type = '' !== $response->content_type ? $response->content_type : 'application/pdf';

		return new Label(
			$response->body,
			$content_type,
			sprintf( 'ozon-label-%s.pdf', $posting_number )
		);
	}
}
