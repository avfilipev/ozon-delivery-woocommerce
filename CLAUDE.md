# CLAUDE.md

Плагин «Ozon Доставка для WooCommerce». Интеграция Ozon Delivery API в WooCommerce.

## Контекст

- Полный план работ, архитектура и фазы: `docs/PLAN.md`
- Справочник по API: `docs/API.md` — **основной источник истины**
- Полная схема: `docs/ozon-delivery.swagger.json` — открывать точечно через `jq`, целиком в контекст не класть
- Стек: PHP 8.1+, WordPress 6.4+, WooCommerce 8.2+, Composer, PSR-4

### Спеки в репозитории сейчас нет

Лежавший здесь `docs/ozon-delivery.swagger.json` оказался не спекой, а 164-байтной страницей редиректа: команда ниже писалась без `-L` и без cookie, поэтому сохраняла ответ testcookie. Файл удалён, чтобы не выглядеть источником истины.

Скачать заново curl-ом **не получится**: `docs.ozon.ru` отдаёт 307 с `Set-Cookie`, а после перехода — 403 с JS-проверкой `abt-challenge`. Спеку нужно сохранить из браузера в `docs/ozon-delivery.swagger.json` (DevTools → Network → swagger.json → Save).

Проверить, что скачалось именно то (должно быть ~171 КБ и 18 путей):
```bash
jq '.paths | keys | length' docs/ozon-delivery.swagger.json
```

До появления файла единственный источник истины — `docs/API.md`.

## Правила

1. **Полей не выдумывать.** Нет в `docs/API.md` и нет в swagger — остановиться и спросить, а не угадать.
2. **Весь HTTP через `Api\Transport`.** Он сам решает OAuth, редиректы `testcookie` (302/307 с сохранением тела POST и cookie), ретраи и 429. Прямой `wp_remote_post` в бизнес-коде запрещён.
3. **HTTP 200 не значит успех.** У `order/checkout`, `order/create`, `delivery/location`, `delivery-point/check-availability` ошибки лежат внутри 200 в `results[].error`. Разбирать всегда.
4. **`order/create` только с заголовком `Idempotency-Key` (UUID) и с `order_external_id`.**
5. **Ошибки расчёта не выбрасывать через `wc_add_notice(..., 'error')`.** Это ловит `check_cart_items()` и валит весь чекаут. Использовать свою строку в таблице заказа плюс флаг в сессии.
6. **Каждая точка принятия решения — через `apply_filters('ozon_delivery_…')`.** Города, выбор точки, тело запроса, тариф, габариты, объявленная стоимость. Чужая заплатка должна писаться сниппетом, а не форком плагина.
7. **Секреты не логируются.** `client_secret`, `access_token`, cookie — маскировать. `x-o3-trace-id` логировать всегда, его спрашивает поддержка Ozon.
8. **Единицы.** Ozon принимает граммы и миллиметры. Конвертация из единиц WooCommerce только в `Shipping\Dimensions`, больше нигде.
9. **Деньги — строки.** `{amount: string, currency_code: string}`. Никакой float-арифметики, сравнение и суммирование в копейках или строками.
10. **Тест пишется первым.** Сначала падающий тест, потом код. Функция без теста в коммит не уходит.
11. **Структуру ответов Ozon не выдумывать даже в тестах.** Сначала записать живой ответ в `tests/Fixtures/`, тест писать против фикстуры. Это единственное исключение из «тест первым».
12. **Красный тест не удаляется и не помечается skipped.** Чинится код.
13. **Чужие плагины и файлы ядра WordPress не редактируются никогда.**
14. Перед коммитом: `composer lint` и `composer test`.

## Песочницы у Ozon нет

Боевой контур один: `https://api-delivery.ozon.ru`. Поэтому:

- В настройках плагина обязателен режим **dry-run**: запросы на запись логируются, но не отправляются.
- Безопасные для прогона методы (ничего не создают): `delivery/check-client`, `delivery/location`, `delivery-point/list`, `delivery-point/info`, `delivery-point/check-availability`, `order/checkout`, `posting/search`, `posting/info`, `return/search`.
- Создают реальные сущности и деньги: `order/create`, `posting/approve`, `posting/label`, `posting/cancel`. Вызывать только осознанно, по одному, с реальным заказом на владельца.

## Команды

```bash
composer install
composer lint          # PHPCS, WordPress Coding Standards
composer test          # PHPUnit
composer analyse       # PHPStan, уровень 6+
npx wp-env start       # локальный WordPress + WooCommerce
```

WP-CLI внутри wp-env:

```bash
wp ozon token                                   # получить и показать статус токена
wp ozon points sync                             # синхронизация каталога ПВЗ
wp ozon checkout <order_id>                     # предрасчёт по заказу
wp ozon push <order_id>                         # передать заказ в Ozon
wp ozon raw /v1/posting/info --json='…' --save-fixture=<name>
```

## wp-env: опасные команды

Плагин в `.wp-env.json` смонтирован как `.` — это значит, что папка плагина внутри контейнера **это и есть корень репозитория** (bind-mount, не копия). `wp plugin uninstall <slug>` внутри контейнера не только запускает `uninstall.php`, но и **удаляет папку плагина с диска** — то есть удаляет весь рабочий каталог на хосте, включая `.git`. Один раз это уже случилось и снесло весь незакоммиченный прогресс.

Правила:
- **Никогда не выполнять `wp plugin uninstall`** (ни через `wp-env run cli`, ни иначе) против этого плагина.
- Логику `uninstall.php`/`Install\Uninstaller` проверять юнит-тестами (уже покрыто) и, при необходимости живой проверки, через `wp eval` с прямым вызовом `(new \Spoki\OzonDelivery\Install\Uninstaller())->run()` — это выполняет ту же бизнес-логику без удаления файлов.
- `wp plugin deactivate` — безопасна, ничего не удаляет.
- Перед любой новой wp-cli командой, в названии которой есть `uninstall`, `delete`, `remove`, `rm -rf` — сначала проверить `docker inspect <container> --format '{{json .Mounts}}'`, что команда не целится в bind-mount рабочего дерева.

## Секреты

Лежат в `.env.local`, файл в `.gitignore`, в репозиторий не попадает. Шаблон — `.env.local.example`.

В коде читаются один раз при бутстрапе и кладутся в опции WordPress через экран настроек. В самом плагине секреты из `.env` не читаются: это только для локальной разработки и CLI.
