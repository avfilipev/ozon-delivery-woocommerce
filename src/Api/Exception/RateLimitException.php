<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Exception;

/**
 * Ozon ответил 429 и попытки закончились.
 *
 * Конкретные лимиты в спецификации не указаны (см. docs/API.md), 429 объявлен
 * у всех методов — вызывающий код должен уметь отложить работу, а не считать
 * это ошибкой запроса.
 */
final class RateLimitException extends ApiException {
}
