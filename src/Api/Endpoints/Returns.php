<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Endpoints;

use Spoki\OzonDelivery\Api\Client;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Order\Label;
use Spoki\OzonDelivery\Order\ReturnInfo;
use Spoki\OzonDelivery\Order\ReturnsPage;
use Spoki\OzonDelivery\Order\ReturnStatusChange;

/**
 * Возвраты и отмены.
 *
 * @see docs/API.md, раздел «Возвраты»
 */
final class Returns {

	private const SEARCH_PATH = '/v1/return/search';

	private const INFO_PATH = '/v1/return/info';

	private const HISTORY_PATH = '/v1/return/status-history';

	private const DOWNLOAD_BARCODE_PATH = '/v1/return/download_barcode';

	private const RESET_BARCODE_PATH = '/v1/return/reset_barcode';

	public const DEFAULT_PAGE_SIZE = 100;

	public function __construct( private readonly Client $client ) {
	}

	public function search( ?string $cursor = null, int $limit = self::DEFAULT_PAGE_SIZE ): ReturnsPage {
		$pagination = array( 'limit' => $limit );

		if ( null !== $cursor && '' !== $cursor ) {
			$pagination['cursor'] = $cursor;
		}

		return ReturnsPage::from_response(
			$this->client->post( self::SEARCH_PATH, array( 'pagination' => $pagination ) )
		);
	}

	/**
	 * @param string[] $return_numbers
	 *
	 * @return array<string, ReturnInfo> Ключ — return_number.
	 */
	public function info( array $return_numbers ): array {
		$numbers = array_values( array_filter( array_map( 'strval', $return_numbers ) ) );

		if ( array() === $numbers ) {
			return array();
		}

		$response = $this->client->post( self::INFO_PATH, array( 'return_numbers' => $numbers ) );

		$raw = isset( $response['returns'] ) && is_array( $response['returns'] ) ? $response['returns'] : array();

		$returns = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$parsed = ReturnInfo::from_response( $item );

			if ( '' !== $parsed->return_number ) {
				$returns[ $parsed->return_number ] = $parsed;
			}
		}

		return $returns;
	}

	/**
	 * @return ReturnStatusChange[]
	 */
	public function status_history( string $return_number ): array {
		$response = $this->client->post( self::HISTORY_PATH, array( 'return_number' => $return_number ) );

		$raw = isset( $response['status_history'] ) && is_array( $response['status_history'] )
			? $response['status_history']
			: array();

		$history = array();

		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$history[] = ReturnStatusChange::from_response( $item );
			}
		}

		return $history;
	}

	/**
	 * PDF со штрихкодом получения возвратов.
	 *
	 * Срок действия указан в самом файле, поэтому получать его нужно
	 * непосредственно перед получением возвратов. Запрос идёт без тела и
	 * ничего не меняет.
	 *
	 * @throws ApiException Ozon вернул пустой ответ.
	 */
	public function download_barcode(): Label {
		$response = $this->client->post_raw( self::DOWNLOAD_BARCODE_PATH, array() );

		if ( '' === $response->body ) {
			throw new ApiException( 'Ozon вернул пустой штрихкод получения возвратов.' );
		}

		$content_type = '' !== $response->content_type ? $response->content_type : 'application/pdf';

		return new Label( $response->body, $content_type, 'ozon-return-barcode.pdf' );
	}

	/**
	 * Сбрасывает штрихкод получения возвратов.
	 *
	 * Обесценивает уже напечатанный штрихкод, поэтому отнесён к методам на
	 * запись и в dry-run не выполняется.
	 *
	 * @return array{barcode: string, expires_at: string}
	 */
	public function reset_barcode(): array {
		$response = $this->client->post( self::RESET_BARCODE_PATH, array() );

		$content = isset( $response['barcode_content'] ) && is_array( $response['barcode_content'] )
			? $response['barcode_content']
			: array();

		return array(
			'barcode'    => isset( $content['barcode'] ) ? (string) $content['barcode'] : '',
			'expires_at' => isset( $content['expires_at'] ) ? (string) $content['expires_at'] : '',
		);
	}
}
