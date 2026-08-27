# Фикстуры — живые ответы Ozon

Правило 11 из `CLAUDE.md`: структуру ответов Ozon нельзя выдумывать даже в тестах.
Сначала записывается живой ответ, тест пишется против него.

Записать ответ:

```bash
wp ozon raw /v1/posting/info --body='{"posting_numbers":["…"]}' --save-fixture=posting-info-delivered
```

Секреты (`access_token`, `client_secret`, cookie, `Authorization`) вычищаются
автоматически — см. `Cli\FixtureWriter`. Но перед коммитом фикстуру всё равно
стоит просмотреть глазами: персональных данных покупателя в репозитории быть
не должно.

## Что ждёт записи

Три места в коде помечены как требующие проверки на живом ответе:

| Что | Где помечено | Чем записать |
|---|---|---|
| Схема ответа точки выдачи токена | `Api\TokenStore` | отдельным запросом к `xapi.ozon.ru/oauth/token` |
| Форма ответа `order/create` (`postings[]` или `results[]`) | `Order\CreatedOrder` | `wp ozon raw /v1/order/create …` |
| Формат этикетки `posting/label` | `Order\Label` | `wp ozon raw /v1/posting/label …` |

Первые два безопасны только на боевом заказе — см. раздел «Песочницы у Ozon нет»
в `CLAUDE.md`.
