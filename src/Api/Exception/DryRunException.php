<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Api\Exception;

/**
 * Запрос на запись не отправлен, потому что включён режим dry-run.
 *
 * Песочницы у Ozon нет, боевой контур один, поэтому order/create,
 * posting/approve, posting/label и posting/cancel в этом режиме только
 * логируются. Исключение, а не поддельный успешный ответ: иначе заказ будет
 * помечен как переданный, хотя в Ozon ничего не ушло.
 */
final class DryRunException extends ApiException {
}
