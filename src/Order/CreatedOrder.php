<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Результат order/create.
 *
 * ВНИМАНИЕ, ТРЕБУЕТ ПРОВЕРКИ НА ЖИВОМ ОТВЕТЕ. В docs/API.md форма ответа
 * описана как `postings[]`, а правило 3 в CLAUDE.md говорит про
 * `results[].error`. Разбираются обе формы — до тех пор, пока живой ответ не
 * будет записан в tests/Fixtures/ и тест переписан против фикстуры
 * (правило 11).
 */
final class CreatedOrder {

	/**
	 * @param CreatedPosting[] $postings
	 */
	public function __construct(
		public readonly string $order_number,
		public readonly array $postings = array(),
		public readonly string $failure = ''
	) {
	}

	/**
	 * @param array<string, mixed> $response Ответ order/create.
	 */
	public static function from_response( array $response ): self {
		$order_number = isset( $response['order_number'] ) ? (string) $response['order_number'] : '';

		$raw = $response['postings'] ?? $response['results'] ?? array();
		$raw = is_array( $raw ) ? $raw : array();

		$postings = array();

		foreach ( $raw as $item ) {
			if ( is_array( $item ) ) {
				$postings[] = CreatedPosting::from_response( $item );
			}
		}

		if ( '' === $order_number ) {
			return new self( '', $postings, 'Ozon не вернул номер заказа.' );
		}

		return new self( $order_number, $postings );
	}

	public function succeeded(): bool {
		return '' === $this->failure && '' !== $this->order_number && ! $this->has_errors();
	}

	public function has_errors(): bool {
		foreach ( $this->postings as $posting ) {
			if ( ! $posting->succeeded() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Первое отправление: в версии 1 оно единственное на заказ.
	 */
	public function first_posting_number(): ?string {
		foreach ( $this->postings as $posting ) {
			if ( '' !== $posting->posting_number ) {
				return $posting->posting_number;
			}
		}

		return null;
	}

	public function error_message(): string {
		if ( '' !== $this->failure ) {
			return $this->failure;
		}

		$messages = array();

		foreach ( $this->postings as $posting ) {
			if ( ! $posting->succeeded() ) {
				$messages[] = $posting->message;
			}
		}

		return implode( ' ', $messages );
	}
}
