<?php

declare(strict_types=1);

namespace Spoki\OzonDelivery\Order;

/**
 * Этикетка отправления.
 *
 * ВНИМАНИЕ, ТРЕБУЕТ ПРОВЕРКИ НА ЖИВОМ ОТВЕТЕ. Схема тела ответа
 * posting/label в спецификации не описана — предположительно это файл.
 * Здесь он и обрабатывается как файл: байты отдаются как есть, тип берётся
 * из Content-Type. Как появится живой ответ — записать фикстуру и уточнить
 * (правило 11).
 */
final class Label {

	public function __construct(
		public readonly string $bytes,
		public readonly string $content_type,
		public readonly string $filename
	) {
	}
}
