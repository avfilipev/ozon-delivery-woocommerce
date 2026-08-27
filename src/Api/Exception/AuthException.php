<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Exception;

/**
 * Получить access_token не удалось: ключи не заданы, Ozon их отверг или
 * ответ точки выдачи токена не разобрать.
 */
final class AuthException extends ApiException {
}
