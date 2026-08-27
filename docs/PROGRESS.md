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
- **`composer analyse` требует `--memory-limit=512M`** — со стабами WooCommerce дефолтных 128M не хватает, зашито в скрипт `analyse` в `composer.json`.

### Живая проверка в wp-env (27.08.2026)

Docker Desktop и `@wordpress/env` (пакет `wp-env` из npm-реестра — заброшенная заглушка, использовать `@wordpress/env`, локально как devDependency) подняты, фаза 0 проверена в реальном WordPress + WooCommerce 11.0.1:

- активация/деактивация плагина — без фаталов, `wp-content/debug.log` пуст;
- `SettingsPage` регистрируется в `woocommerce_get_settings_pages`;
- `client_secret` не встречается в отрендеренном HTML экрана настроек — только маркер;
- сохранение полей и «пустой ввод секрета не затирает старое значение» подтверждено на реальном `WC_Settings_Page::save()`, а не только в юнит-тестах;
- `Install\Uninstaller` удаляет все шесть опций плагина, файлы не трогает.

**Инцидент во время проверки.** Выполнил `wp plugin uninstall ozon-delivery-woocommerce` внутри контейнера — эта команда не только гоняет `uninstall.php`, но и удаляет папку плагина с диска. Плагин в `.wp-env.json` смонтирован как `.`, то есть его «папка» — это корень репозитория. В результате был удалён весь рабочий каталог, включая `.git`. Спасло то, что фаза 0 была запушена в `origin` до инцидента — код восстановлен переклонированием, ничего не потеряно, кроме `.env.local` (секретов, они и не были в git) и незакоммиченного `package.json`. Правило зафиксировано в `CLAUDE.md` (раздел «wp-env: опасные команды»): `wp plugin uninstall` для этого плагина не запускать никогда, логику `Uninstaller` проверять через `wp eval` с прямым вызовом `run()`.

Также встретилась не связанная с плагином особенность локальной среды: `dns.resolve()` в Node ломается на этой машине из-за того, что macOS не пишет реальные записи в `/etc/resolv.conf` (сеть в целом работает, обычный `dns.lookup()`/curl — нет). Из-за этого `wp-env` считает себя офлайн и не выкачивает WordPress/WooCommerce сам — обходится вручную через `wp plugin install woocommerce --activate` внутри контейнера (у Linux-контейнера с DNS всё в порядке).

### Что дальше — фаза 1 (по `docs/PLAN.md`)

1. **`Api\Transport`** — редиректы `testcookie` (302/307, сохранение тела POST и cookie), ретраи, 429. Согласно `docs/PLAN.md`, это самая рискованная часть, поэтому она идёт сразу за каркасом, а не в конце.
2. **`Api\TokenStore`** — OAuth `client_credentials`, кэш токена, обновление до истечения, реакция на 401.
3. Кнопка «Проверить подключение» и страница диагностики в админке.

Секреты для ручной проверки фазы 1 (client_id/client_secret/scope/shipment_method_id) — из `.env.local`, читаются только вручную через экран настроек, не в коде плагина.
