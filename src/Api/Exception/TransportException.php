<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Exception;

/**
 * Ответ от Ozon получить не удалось: сетевая ошибка, таймаут или редирект,
 * который не удалось разобрать. Отличается от HTTP-ответа с кодом ошибки —
 * тот возвращается вызывающему коду как обычный Response.
 */
final class TransportException extends ApiException {
}
