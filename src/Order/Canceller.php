<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

use Spoki\OzonDelivery\Api\ClientFactory;
use Spoki\OzonDelivery\Api\Endpoints\Postings;
use Spoki\OzonDelivery\Api\Exception\ApiException;
use Spoki\OzonDelivery\Api\Exception\DryRunException;
use Spoki\OzonDelivery\Support\Logger;

/**
 * Отмена отправления с обязательной проверкой результата.
 *
 * posting/cancel отвечает 200 без тела, но это не значит, что отмена
 * произошла: документация Ozon прямо велит смотреть результат через
 * posting/info. Поэтому статус перечитывается, и успехом считается только
 * реально сменившийся на CANCELED.
 *
 * Исключения наружу не выходят — иначе кнопка в админке роняла бы страницу.
 */
final class Canceller {

	public function __construct(
		private readonly Postings $postings,
		private readonly StatusSync $sync,
		private readonly Logger $logger
	) {
	}

	public static function create(): self {
		$logger   = new Logger();
		$postings = new Postings( ClientFactory::create() );

		return new self( $postings, new StatusSync( $postings, $logger ), $logger );
	}

	public function cancel( object $order ): bool {
		$posting_number = Meta::posting_number( $order );

		if ( null === $posting_number ) {
			return false;
		}

		$status = new PostingStatus( (string) $order->get_meta( Meta::POSTING_STATUS ) );

		// Отмена недоступна после вручения — незачем и запрос слать.
		if ( ! $status->can_be_canceled() ) {
			$this->note( $order, sprintf( 'отменить нельзя, текущий статус — %s.', $status->label() ) );

			return false;
		}

		try {
			$result = $this->postings->cancel( $posting_number );
		} catch ( DryRunException $e ) {
			$this->note( $order, $e->getMessage() );

			return false;
		} catch ( ApiException $e ) {
			$this->note( $order, $e->getMessage() );

			return false;
		}

		if ( ! $result->succeeded ) {
			$this->note( $order, $result->message );

			return false;
		}

		// «200 без тела» отменой ещё не является: проверяем статус.
		$info = $this->sync->sync_order( $order );

		if ( null === $info || ! $info->status->is_canceled() ) {
			$this->note(
				$order,
				'запрос на отмену принят, но отправление ещё не отменено. Проверьте статус позже.'
			);

			return false;
		}

		$this->logger->log( 'info', 'Отправление отменено', array( 'posting_number' => $posting_number ) );

		/**
		 * Отправление отменено в Ozon.
		 *
		 * @param object      $order Заказ WooCommerce.
		 * @param PostingInfo $info  Состояние отправления.
		 */
		do_action( 'ozon_delivery_posting_canceled', $order, $info );

		return true;
	}

	private function note( object $order, string $message ): void {
		$order->add_order_note( sprintf( 'Ozon Доставка: %s', $message ) );

		$this->logger->log( 'warning', 'Отмена отправления не выполнена', array( 'reason' => $message ) );
	}
}
