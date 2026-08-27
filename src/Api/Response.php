<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api;

/**
 * HTTP-ответ Ozon в том виде, в каком его отдаёт Transport.
 *
 * Тело не разбирается: у order/checkout, order/create, delivery/location и
 * delivery-point/check-availability ошибки лежат внутри 200 в results[].error,
 * поэтому решать, успех это или нет, — задача вызывающего кода.
 */
final class Response {

	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly string $trace_id,
		/**
		 * Нужен там, где Ozon отдаёт файл, а не JSON: этикетка posting/label.
		 */
		public readonly string $content_type = ''
	) {
	}
}
