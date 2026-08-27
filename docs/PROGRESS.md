# Прогресс

## Фаза 0 — каркас: сделано (26.08.2026)

Репозиторий, инструментарий, guards, настройки, логгер. Бизнес-логики и обращений к API нет — по плану её здесь и не должно быть.

### Что появилось

| Файл | Назначение | Тест |
|---|---|---|
| `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist` | PSR-4 `Spoki\OzonDelivery` в `src/`, WordPress Coding Standards (Core+Extra), PHPStan уровень 6 со стабами WP/WooCommerce, PHPUnit + Brain Monkey | — (конфиг) |
| `.wp-env.json` | WordPress + WooCommerce (latest-stable), плагин смонтирован как `.` | — |
| `src/Support/Requirements.php` | Сравнение PHP 8.1+ / WP 6.4+ / WC 8.2+ с установленными версиями, список читаемых сообщений вместо throw | `tests/Unit/Support/RequirementsTest.php` |
| `src/Support/Logger.php` | Обёртка над `wc_get_logger()`, источник `ozon-delivery`, рекурсивное маскирование `client_secret`/`access_token`/`cookie` (регистронезависимо), `x-o3-trace-id` не маскируется | `tests/Unit/Support/LoggerTest.php` |
| `src/Install/Migrations.php` | Версия схемы в опции `ozon_delivery_db_version`, идемпотентный `run()` | `tests/Unit/Install/MigrationsTest.php` |
| `src/Install/Uninstaller.php` + `uninstall.php` | Удаление всех опций плагина при удалении из WP | `tests/Unit/Install/UninstallerTest.php` |
| `src/Admin/Settings.php` | Пять полей настроек (client_id, client_secret, scope, shipment_method_id, dry_run), санитизация, секрет не затирается пустым вводом | `tests/Unit/Admin/SettingsTest.php` |
| `src/Admin/SettingsPage.php` | Обвязка `Settings` под WooCommerce Settings API, вкладка «Ozon Доставка». Секрет выводится пустым полем с плейсхолдером — в HTML не попадает | не юнит-тестируется (см. ниже) |
| `src/Plugin.php` | `boot()` — регистрация хуков; `declare_compatibility()` — HPOS да, `cart_checkout_blocks` нет; `activate()` — стемпит версию схемы | `tests/Unit/PluginTest.php` (кроме `register_settings_page()`) |
| `ozon-delivery-for-woocommerce.php` | Заголовок плагина, `plugins_loaded` → `Requirements::check()` → либо `admin_notices`, либо `Plugin::boot()`; `register_activation_hook` на верхнем уровне | не юнит-тестируется (bootstrap-файл) |

Коммиты — по одному на зелёный кусок, история в `git log`.

### Итог прогона

```
composer lint     — 17/17, без замечаний
composer test     — 33 теста, 38 assertions, OK
composer analyse  — уровень 6, 0 ошибок (только nag о версии PHPStan, не ошибка)
```

### Архитектурные решения, которые стоит знать

- **Что юнит-тестируется, а что нет.** Всё, что содержит принятие решений (сравнение версий, санитизация, маскирование, версия схемы, wiring хуков), покрыто тестами через Brain Monkey/Mockery. WC-специфичный «клей» — `SettingsPage` (наследует `WC_Settings_Page`, которого нет без загруженного WooCommerce) и `Plugin::register_settings_page()` — тестами не покрыт по объективной причине: класс WooCommerce нельзя ни замокать через `extends`, ни загрузить в чистом PHPUnit-окружении. Так же не тестируются `uninstall.php` и главный файл плагина — это стандартные WP-точки входа. Все они **проверены живьём в wp-env 27.08.2026** (см. раздел ниже) — активация, экран настроек, сохранение полей, маскирование секрета, удаление опций подтверждены на реальном WooCommerce 11.0.1, не только на стабах.
- **Секрет `client_secret` не выводится в открытом виде.** Кастомный тип поля WC (`woocommerce_admin_field_ozon_secret` / `woocommerce_update_option_ozon_secret`) — поле всегда рендерится пустым с плейсхолдером-маркером «сохранено», при пустой отправке старое значение не трогается.
- **Ruleset PHPCS сужен до WordPress-Core + WordPress-Extra**, без WordPress-Docs — с ним требуются полные докблоки на каждый метод, включая тестовые, что превращается в чистый шум. Core+Extra по-прежнему полноценный WPCS (табы, экранирование вывода, нонсы, именование и т.д.).
- **`composer analyse` требует `--memory-limit=1G`** — со стабами WooCommerce дефолтных 128M не хватает, зашито в скрипт `analyse` в `composer.json`.

### Живая проверка в wp-env (27.08.2026)

Docker Desktop и `@wordpress/env` (пакет `wp-env` из npm-реестра — заброшенная заглушка, использовать `@wordpress/env`, локально как devDependency) подняты, фаза 0 проверена в реальном WordPress + WooCommerce 11.0.1:

- активация/деактивация плагина — без фаталов, `wp-content/debug.log` пуст;
- `SettingsPage` регистрируется в `woocommerce_get_settings_pages`;
- `client_secret` не встречается в отрендеренном HTML экрана настроек — только маркер;
- сохранение полей и «пустой ввод секрета не затирает старое значение» подтверждено на реальном `WC_Settings_Page::save()`, а не только в юнит-тестах;
- `Install\Uninstaller` удаляет все шесть опций плагина, файлы не трогает.

**Инцидент во время проверки.** Выполнил `wp plugin uninstall ozon-delivery-woocommerce` внутри контейнера — эта команда не только гоняет `uninstall.php`, но и удаляет папку плагина с диска. Плагин в `.wp-env.json` смонтирован как `.`, то есть его «папка» — это корень репозитория. В результате был удалён весь рабочий каталог, включая `.git`. Спасло то, что фаза 0 была запушена в `origin` до инцидента — код восстановлен переклонированием, ничего не потеряно, кроме `.env.local` (секретов, они и не были в git) и незакоммиченного `package.json`. Правило зафиксировано в `CLAUDE.md` (раздел «wp-env: опасные команды»): `wp plugin uninstall` для этого плагина не запускать никогда, логику `Uninstaller` проверять через `wp eval` с прямым вызовом `run()`.

Также встретилась не связанная с плагином особенность локальной среды: `dns.resolve()` в Node ломается на этой машине из-за того, что macOS не пишет реальные записи в `/etc/resolv.conf` (сеть в целом работает, обычный `dns.lookup()`/curl — нет). Из-за этого `wp-env` считает себя офлайн и не выкачивает WordPress/WooCommerce сам — обходится вручную через `wp plugin install woocommerce --activate` внутри контейнера (у Linux-контейнера с DNS всё в порядке).

---

## Фаза 1 — транспорт и авторизация: сделано (27.08.2026)

| Файл | Назначение | Тест |
|---|---|---|
| `src/Api/CookieJar.php` | Хранилище cookie testcookie: разбор `Set-Cookie`, отбрасывание атрибутов, слияние, перезапись по имени, транзиент на 12 часов | `tests/Unit/Api/CookieJarTest.php` |
| `src/Api/Transport.php` | Единственная точка выхода в сеть: редиректы 302/307 с сохранением POST и тела, cookie, ретраи с экспоненциальной паузой, 429, `Retry-After`, `x-o3-trace-id` | `tests/Unit/Api/TransportTest.php` |
| `src/Api/TokenStore.php` | OAuth `client_credentials`, кэш токена на `expires_in` минус 60 с, `forget()` при 401 | `tests/Unit/Api/TokenStoreTest.php` |
| `src/Api/Credentials.php` | Ключи частного приложения из настроек | `tests/Unit/Api/CredentialsTest.php` |
| `src/Api/Client.php` | Адрес, `Authorization`, JSON, разбор ошибок HTTP, dry-run, обновление токена по 401, обязательный `Idempotency-Key` у `order/create` | `tests/Unit/Api/ClientTest.php` |
| `src/Api/ClientFactory.php` | Сборка клиента из настроек, безопасное умолчание dry-run | `tests/Unit/Api/ClientFactoryTest.php` |
| `src/Admin/HealthCheck.php` | Проверка подключения через `delivery-point/list` с лимитом 1 | `tests/Unit/Admin/HealthCheckTest.php` |
| `src/Admin/SettingsPage.php` | Кнопка «Проверить подключение» (admin-post, nonce, `manage_woocommerce`) | не юнит-тестируется, проверено в wp-env |

Исключения: `ApiException` (база), `TransportException`, `RateLimitException`, `AuthException`, `DryRunException`.

```
composer lint     — 39/39, без замечаний
composer test     — 130 тестов, 196 assertions, OK
composer analyse  — уровень 6, 0 ошибок
```

### Решения, которые стоит знать

- **Контракт `Transport`.** Любой полученный HTTP-ответ возвращается как `Response`, включая 4xx и 5xx — у них в спеке описано тело `error.code`/`error.message`. Исключение бросается только когда ответа нет вовсе (сеть, зацикленный редирект) или когда 429 не ушёл за отведённые попытки.
- **Ретраи и идемпотентность.** Отключены для `posting/approve`, `posting/label`, `posting/cancel` — там идемпотентность ничем не гарантирована. Для `order/create` включены: его защищает `Idempotency-Key`, и `Transport` шлёт тот же ключ во всех попытках. Редиректы testcookie обрабатываются всегда, даже там, где ретраи запрещены: это штатный механизм, а не сбой.
- **Dry-run бросает `DryRunException`, а не возвращает поддельный успех.** Иначе заказ будет помечен переданным, хотя в Ozon ничего не ушло.
- **Тела запросов и ответов не логируются никогда.** В них лежат `access_token` и персональные данные покупателя. Логируются статус, адрес, `x-o3-trace-id` и заголовки (с маскированием).
- **Дыра в `Logger`, закрытая по пути.** Маскировались только `client_secret`, `access_token` и `cookie`; заголовки `Authorization` (несёт токен) и `Set-Cookie` уходили в журнал открытым текстом, а прежний тест это закреплял. Добавлены `authorization`, `set-cookie`, `refresh_token`.

### Открытые вопросы фазы 1

1. **Схема ответа точки выдачи токена не подтверждена.** `docs/API.md` описывает только параметры запроса. Разбор ответа и form-urlencoded тело сделаны по RFC 6749 (§4.4, §5.1) — по стандарту, который `docs/API.md` называет прямо. Как появится живой ответ: записать в `tests/Fixtures/` и переписать тест против фикстуры (правило 11). Помечено в докблоке `TokenStore`.
2. **Полной спеки в репозитории нет** — см. раздел в `CLAUDE.md`. До неё единственный источник истины `docs/API.md`.
3. **Ничего не проверено на живом API**: нет ключей. Всё выше — юнит-тесты и статический анализ.

---

## Фаза 2 — каталог ПВЗ: сделано (27.08.2026)

| Файл | Назначение | Тест |
|---|---|---|
| `src/Support/Money.php` | Деньги строками, сравнение и сложение в минорных единицах — правило 9 | `tests/Unit/Support/MoneyTest.php` |
| `src/Shipping/Dimensions.php` | Единственное место конвертации единиц WooCommerce в граммы и миллиметры — правило 8 | `tests/Unit/Shipping/DimensionsTest.php` |
| `src/Points/Restrictions.php` | Ограничения точки: вес, габариты, объявленная стоимость | `tests/Unit/Points/RestrictionsTest.php` |
| `src/Points/DeliveryPoint.php` | Модель ПВЗ, разбор ответа, сериализация в строку таблицы | `tests/Unit/Points/DeliveryPointTest.php` |
| `src/Api/ErrorCodes.php` | Словарь кодов ошибок внутри 200 — правило 3 | `tests/Unit/Api/ErrorCodesTest.php` |
| `src/Api/Endpoints/DeliveryPoints.php` | `list` с курсором, `info` пачками, `check-availability` | `tests/Unit/Api/Endpoints/DeliveryPointsTest.php` |
| `src/Install/Migrations.php` | Таблица `wp_ozon_delivery_points`, схема 1.1.0 | `tests/Unit/Install/MigrationsTest.php` |
| `src/Points/Repository.php`, `PointQuery.php` | Локальный каталог, фильтрация по городу, bbox, методу и ограничениям в SQL | `tests/Unit/Points/RepositoryTest.php` |
| `src/Points/CatalogSync.php`, `SyncState.php`, `CatalogPage.php` | Обход каталога с курсором, устойчивый к обрыву | `tests/Unit/Points/CatalogSyncTest.php` |
| `src/Points/Availability.php`, `PointAvailability.php` | Подбор точек: локальный фильтр + подтверждение у Ozon | `tests/Unit/Points/AvailabilityTest.php` |
| `src/Jobs/SyncPointsJob.php` | Фоновый обход через Action Scheduler | `tests/Unit/Jobs/SyncPointsJobTest.php` |
| `src/Admin/CatalogStatus.php` | Состояние каталога для админки | `tests/Unit/Admin/CatalogStatusTest.php` |

```
composer lint     — 65/65, без замечаний
composer test     — 303 теста, 513 assertions, OK
composer analyse  — уровень 6, 0 ошибок
```

### Решения, которые стоит знать

- **Фильтрация по ограничениям идёт в SQL, а не в PHP.** Смысл в том, чтобы не тащить в `check-availability` точки, которые заведомо не примут отправление. `NULL` в ограничении означает «предела нет».
- **Суммы в разных валютах не сравниваются.** Ни в `Restrictions`, ни в SQL: молча спрятать точку неправильно, пусть решает Ozon.
- **Точка без `is_active` считается нерабочей.** Лучше скрыть, чем отправить заказ в закрытый ПВЗ. Точка, о которой `check-availability` промолчал, тоже убирается: молчание — не подтверждение.
- **`delete_stale` только после полного обхода.** Снести устаревшие точки на середине означало бы выкосить половину каталога из-за одного обрыва связи.
- **Ошибка шага не роняет Action Scheduler.** Выпущенное исключение пометило бы задачу упавшей и оборвало расписание; вместо этого шаг ставится заново с паузой, курсор сохранён.
- **Города отдельным полем Ozon не отдаёт** — он вытаскивается из адреса эвристикой и проходит через фильтр `ozon_delivery_point_city`. Разбор адресов лотерея, чужая правка должна писаться сниппетом.

### Живая проверка в wp-env (27.08.2026)

- таблица создаётся при активации: 23 колонки, `db_version = 1.1.0`;
- запись точки → чтение обратно сохраняет имя, город (выведенный из адреса), координаты и методы доставки;
- фильтрация в SQL работает на реальной MySQL: слишком тяжёлое отправление → 0 точек, чужой метод доставки → 0, bbox по Москве → 1, дальний bbox → 0;
- блок каталога и кнопка «Обновить каталог» рендерятся на вкладке настроек;
- ежедневное обновление реально стоит в Action Scheduler после активации;
- `debug.log` пуст.

---

## Фаза 3 — метод доставки до ПВЗ: сделано (27.08.2026)

| Файл | Назначение | Тест |
|---|---|---|
| `src/Shipping/Destination.php` | Пункт выдачи или курьер по координатам | `tests/Unit/Shipping/DestinationTest.php` |
| `src/Api/Endpoints/Delivery.php` | `check-client`, `location` | `tests/Unit/Api/Endpoints/DeliveryTest.php` |
| `src/Api/Endpoints/Orders.php` | `order/checkout` — стоимость, страховка, срок | `tests/Unit/Api/Endpoints/OrdersTest.php` |
| `src/Shipping/Packer.php`, `CartItem.php` | Упаковка корзины в одно отправление | `tests/Unit/Shipping/PackerTest.php` |
| `src/Shipping/DeclaredValue.php` | Объявленная стоимость процентом от заказа | `tests/Unit/Shipping/DeclaredValueTest.php` |
| `src/Shipping/PackageReader.php` | Чтение корзины WooCommerce | `tests/Unit/Shipping/PackageReaderTest.php` |
| `src/Shipping/RateCalculator.php`, `PackageSignature.php` | Расчёт тарифа с кэшем | `tests/Unit/Shipping/RateCalculatorTest.php` |
| `src/Shipping/QuoteBuilder.php` | Единая точка расчёта из настроек | `tests/Unit/Shipping/QuoteBuilderTest.php` |
| `src/Shipping/MethodPickup.php` | Метод доставки WooCommerce | не юнит-тестируется, проверен в wp-env |
| `src/Checkout/ClientCheck.php` | Проверка покупателя по телефону, с кэшем | `tests/Unit/Checkout/ClientCheckTest.php` |
| `src/Checkout/SessionState.php` | Выбранный ПВЗ и сообщения в сессии | `tests/Unit/Checkout/SessionStateTest.php` |
| `src/Checkout/PointPicker.php` | Поиск и выбор точки, AJAX | `tests/Unit/Checkout/PointPickerTest.php` |
| `src/Checkout/CheckoutHooks.php` | Регистрация метода, перенос ПВЗ в заказ | `tests/Unit/Checkout/CheckoutHooksTest.php` |
| `src/Checkout/PickerField.php`, `assets/js/checkout-point-picker.js` | Поле выбора на чекауте | не юнит-тестируется, проверено в wp-env |
| `src/Order/Meta.php` | ПВЗ, расчёт и идентификаторы Ozon в заказе | `tests/Unit/Order/MetaTest.php` |

```
composer lint     — 78/78, без замечаний
composer test     — 459 тестов, 772 assertions, OK
composer analyse  — уровень 6, 0 ошибок
```

### Решения, которые стоит знать

- **Правило 5 соблюдено буквально.** Во всём плагине нет ни одной `wc_add_notice(..., 'error')`. Нет тарифа — метод просто не показывается, причина уходит своей строкой под таблицей доставки и в мету заказа.
- **Кэш расчёта обязателен.** WooCommerce пересчитывает доставку на каждое изменение корзины и на каждую загрузку чекаута. Отпечаток включает всё, что влияет на цену; телефон попадает в ключ только хешем. Неудача кэшируется на 2 минуты против 15 у успеха: иначе каждое обновление корзины долбит лежащий API.
- **`check-client` при недоступности API не прячет метод.** Знать вердикт неоткуда, а настоящей проверкой всё равно будет `order/checkout`, который при той же недоступности сам не отдаст тариф. Такой «ответ» не кэшируется.
- **Больше 100% объявленной стоимости не пропускается** — почти всегда это опечатка (10000 вместо 100). Осознанный случай закрывается фильтром.
- **Поиск точек идёт по локальному каталогу**, а не по API: `delivery-point/list` не умеет искать по городу и отдаёт только идентификаторы.

### Живая проверка в wp-env (27.08.2026)

Через подмену `pre_http_request` — приём из плана:

- сквозной расчёт: токен → `order/checkout` → разбор ответа, итог 375.00 (350 доставка + 25 страховка);
- конвертация единиц следует настройке магазина, а не захардкожена: при `lbs`/`in` товар 1.5×30 дал 680 г и 772 мм, после переключения на `kg`/`cm` — 1500 г и 310 мм (300 + 10 запаса);
- метод доставки зарегистрирован в WooCommerce, поддерживает зоны;
- без выбранной точки — 0 тарифов и понятное сообщение, без фатала; с точкой — тариф 350.00;
- поиск ПВЗ по городу, выбор, отказ на исчезнувшую точку и обе AJAX-ручки работают;
- `debug.log` пуст.

### Две ловушки, найденные по дороге

Обе роняют PHPUnit **молча**, с кодом возврата 0, — описаны в `CLAUDE.md`:

1. `defined( 'ABSPATH' ) || exit;` в файлах классов: в юнит-тестах `ABSPATH` нет, автозагрузка завершает процесс. Из `src/` убрано, в точках входа оставлено.
2. Обращение к константе класса, наследующего класс WooCommerce (`MethodPickup extends WC_Shipping_Method`): без WooCommerce его нельзя даже автозагрузить. Идентификаторы вынесены в `Shipping\Methods`.

---

## Что дальше

1. **Добавить ключи** в настройки WooCommerce (`.env.local` потерян и в git его не было) и нажать «Проверить подключение» — это первый живой прогон OAuth и testcookie. Затем «Обновить каталог» — первый настоящий обход ПВЗ.
2. Записать живой ответ токена в `tests/Fixtures/`, закрыть открытый вопрос по схеме ответа OAuth.
3. Сохранить спеку из браузера в `docs/ozon-delivery.swagger.json`.
4. Фаза 4 — передача заказа: `order/create` с `Idempotency-Key`, разбор per-posting ошибок, мета с `order_number` и `posting_number`.

