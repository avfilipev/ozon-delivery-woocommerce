# Ozon Delivery API — рабочий справочник

Источник: `https://docs.ozon.ru/api/ozon-delivery/swagger.json` (OpenAPI 3.0.4, 18 методов).
Выжимка сделана 26.08.2026. **Если поля нет здесь — смотреть в swagger, не выдумывать.**

Полную схему держать в `docs/ozon-delivery.swagger.json`, обновлять командой:

```bash
curl -sS https://docs.ozon.ru/api/ozon-delivery/swagger.json -o docs/ozon-delivery.swagger.json
```

Текстовые разделы документации (Introduction, GetStarted, Process, Status Model, Error Codes) лежат в том же файле, в `tags[].description`, в Markdown.

---

## 1. Подключение

| Параметр | Значение |
|---|---|
| База API | `https://api-delivery.ozon.ru` |
| Токен | `POST https://xapi.ozon.ru/oauth/token` |
| Grant | `client_credentials` |
| Параметры токена | `client_id`, `client_secret`, `grant_type`, `scope` |
| Заголовок | `Authorization: Bearer <access_token>` |
| Тело | `Content-Type: application/json` |
| Все методы | `POST`, GET-методов нет |

Ключи: личный кабинет Ozon Доставки → Настройки → Управление частными приложениями → Создать. Доступ к приложению можно выдать разработчику отдельно.

Scope:

| Scope | Методы |
|---|---|
| `delivery-api.delivery` | `/v1/delivery/*` |
| `delivery-api.delivery-point` | `/v1/delivery-point/*` |
| `delivery-api.order` | `/v1/order/*` |
| `delivery-api.posting` | `/v1/posting/*` |
| `delivery-api.return` | `/v1/return/*` |
| `delivery-api.all` | всё |

### Защита testcookie (обязательно к реализации)

Серверы закрыты анти-DDoS модулем. Ожидаемое поведение клиента:

1. Сервер отвечает редиректом **302 или 307** с заголовками `Location` и `Set-Cookie`.
2. Клиент повторяет запрос **с тем же телом и методом** по адресу из `Location`, приложив полученный `Cookie`.
3. Клиент хранит cookie и шлёт его в последующих запросах.
4. Значение cookie уникально, зашифровано и **может измениться без уведомления**, поэтому редирект надо уметь обработать в любой момент, а не только на первом запросе.

В PHP: `wp_remote_post()` на 302 через cURL-транспорт превращает POST в GET и теряет тело. Нужен ручной цикл редиректов (`redirection => 0`, разбор `Location` и `Set-Cookie`, повтор POST) либо `CURLOPT_POSTREDIR` через фильтр `http_api_curl`. Cookie хранить в транзиенте.

### Служебное

- Все ответы содержат заголовок **`x-o3-trace-id`**. Логировать всегда: это то, что спрашивает поддержка Ozon.
- Заголовок **`Idempotency-Key`** (UUID) — на `/v1/order/create`. Повторный запрос с тем же ключом возвращает исходный ответ.
- Код **429** объявлен у всех методов. Конкретные лимиты в документации не указаны, уточнять у Ozon.

### Формат ошибок HTTP (400/401/403/404/429/500)

```
error.code: string
error.message*: string
details[]: { item_id*: string, error: { code, message* } }
```

**Отдельно и важно:** у `order/checkout`, `order/create`, `delivery/location`, `delivery-point/check-availability` ошибки приходят **внутри 200-го ответа**, поэлементно, в `results[].error`. HTTP 200 успехом не является.

---

## 2. Методы

### 2.1 Доступность и сроки

**`POST /v1/delivery/check-client`**
```
REQ:  phone_number*: string
RESP: can_be_delivered*: boolean
```
Доставка доступна только покупателям, зарегистрированным на Ozon.

**`POST /v1/delivery/location`** — предварительный срок до выбора товара.
```
REQ:  coordinates*{latitude*, longitude*}
      shipment_methods*[]{ shipment_method_id*, cutoff_at }
RESP: results*[]{ shipment_method_id*, cutoff_at*, estimated_delivery_days,
                  error{code, message*} }
```
По умолчанию расчёт от текущего момента; при указании `cutoff_at` — от планового момента отгрузки.

### 2.2 Пункты выдачи

**`POST /v1/delivery-point/list`** — полный каталог, курсорная пагинация. Возвращает только идентификаторы.
```
REQ:  pagination*{ cursor, limit* }
RESP: delivery_points*[]{ delivery_point_id*, shipment_method_ids*[] }
      next_cursor
```

**`POST /v1/delivery-point/info`** — детали пачкой.
```
REQ:  delivery_point_ids*: integer[]
RESP: delivery_points*[]{
        delivery_point_id*, name*, delivery_point_number, type*, full_address*,
        coordinates{latitude*, longitude*},
        schedule*[]{ date*, periods*[]{ from_local*, to_local* } },
        is_active*, storage_period_days, fitting_rooms_count, is_bulky,
        restrictions{ min_weight_g*, max_weight_g*, max_width_mm*, max_length_mm*,
                      max_height_mm*, min_price{amount*,currency_code*},
                      max_price{amount*,currency_code*} } }
```

**`POST /v1/delivery-point/check-availability`** — подойдут ли точки под конкретный заказ.
```
REQ:  delivery_point_ids*: integer[]
      shipment_method_id*
      postings*[]{ request_id*, cutoff_at,
                   declared_value*{amount*, currency_code*},
                   dimensions*{weight_g*, length_mm*, width_mm*, height_mm*} }
RESP: results*[]{ request_id*, delivery_point_id*, cutoff_at, error{code,message*} }
```

### 2.3 Расчёт и заказ

**`POST /v1/order/checkout`**
```
REQ:  recipient*{ phone_number* }
      postings*[]{ request_id*, shipment_method_id*, cutoff_at,
                   declared_value*{amount*, currency_code*},
                   dimensions*{weight_g*, length_mm*, width_mm*, height_mm*} }
      delivery*{ delivery_point{ delivery_point_id* }
                 | courier{ coordinates*{latitude*, longitude*} } }
RESP: results*[]{ request_id*,
                  posting{ estimated_delivery_cost{amount*, currency_code*},
                           estimated_insurance_cost{amount*, currency_code*},
                           estimated_delivery_days*, cutoff_at* },
                  error{code, message*} }
```

**`POST /v1/order/create`** — заголовок `Idempotency-Key`.
```
REQ:  order_external_id
      recipient*{ phone_number*, full_name }
      delivery*{ delivery_point{ delivery_point_id* }
                 | courier{ coordinates*{latitude*, longitude*}, zip_code*, country*,
                            region*, city*, street*, house_number, entrance, floor,
                            apartment, intercom } }
      postings*[]{ request_id*, posting_external_id, shipment_method_id*,
                   description*, declared_value*{amount*, currency_code*},
                   cutoff_at, dimensions*{weight_g*, length_mm*, width_mm*, height_mm*} }
RESP: order_number*, order_external_id,
      postings*[]{ request_id*, posting_number*, posting_external_id,
                   estimated_delivery_cost{...}, … }
```

Перед `create` рекомендуется повторить `checkout` с теми же параметрами, чтобы убедиться в актуальности расчёта.

**Ни SKU, ни артикулов Ozon в схеме нет.** Содержимое отправления описывается свободным текстом `description`.

### 2.4 Отправления

**`POST /v1/posting/approve`** — `{ posting_number* }` → 200 без тела. Проверяется баланс кабинета. При успехе статус становится `READY_FOR_SHIPPING`, результат смотреть через `posting/info`.

**`POST /v1/posting/label`** — `{ posting_number* }` → 200. Схема тела ответа в спеке не описана (вероятно файл), проверить на живом запросе. Доступна только в статусе `READY_FOR_SHIPPING`, обязательна для приёмки.

**`POST /v1/posting/cancel`** — `{ posting_number* }` → 200 без тела. Отмена недоступна после `DELIVERED`. Результат проверять через `posting/info`.

**`POST /v1/posting/search`**
```
REQ:  filters{ statuses[], shipment_method_id, created_at_from, created_at_to }
      pagination*{ cursor, limit* }
RESP: postings*[]{ … те же поля, что в posting/info … }, next_cursor
```

**`POST /v1/posting/info`**
```
REQ:  posting_numbers*: string[]
RESP: postings*[]{ posting_number*, posting_external_id, order_number*, created_at*,
        status*, status_changed_at*, description*,
        delivery*{ shipment_method_id*, type*, delivery_point_id, full_address* },
        estimated_delivery_days, original_delivery_at, delivery_at,
        dimensions*{…}, estimated_delivery_cost{…}, estimated_insurance_cost{…},
        declared_value{…}, recipient*{ full_name* } }
```

**`POST /v1/posting/status-history`**
```
REQ:  posting_number*
RESP: history*[]{ status*, status_changed_at* }
```
Только прямой поток. Возвратный смотреть в `return/status-history`.

### 2.5 Возвраты

**`POST /v1/return/search`**
```
REQ:  pagination*{ cursor, limit* }
RESP: returns*[]{ return_number*, return_external_id, created_at*, barcode,
        return_type*, description*, dimensions*{…}, declared_value*{…},
        status*, status_changed_at*, shipment_method_id*, return_delivery_type*,
        current_placement_name, current_placement_address,
        cancellation_responsible, cancellation_reason }
      next_cursor
```

**`POST /v1/return/info`** — `{ return_numbers*: string[] }` → те же поля без `created_at` и `status_changed_at`.

**`POST /v1/return/status-history`** — `{ return_number* }` → `status_history*[]{ return_status*, changed_at* }`.

**`POST /v1/return/download_barcode`** — без тела запроса, 200 без JSON: PDF со штрихкодом получения возвратов. Срок действия указан в файле, получать непосредственно перед получением возвратов.

**`POST /v1/return/reset_barcode`** — без тела запроса → `barcode_content{ barcode*, expires_at* }`.

---

## 3. Статусы

### Отправление
| Статус | Значение |
|---|---|
| `CREATED` | создано, не подтверждено, отгружать нельзя |
| `FORMING` | в процессе подтверждения |
| `FORMING_FAILED` | ошибка подтверждения |
| `READY_FOR_SHIPPING` | подтверждено, готово к отгрузке, доступна этикетка |
| `ON_WAY` | принято в логистику, едет к получателю |
| `IN_DELIVERY_POINT` | доставлено в ПВЗ или постамат |
| `IN_COURIER_SERVICE` | передано курьеру |
| `DELIVERED` | выдано получателю, отмена недоступна |
| `CANCELED` | отменено, дальше отслеживается как возврат |

### Возврат
`MOVING` едет к продавцу · `AT_THE_PICK_UP_POINT` ждёт в ПВЗ · `RECEIVED` получен продавцом · `UTILIZATION` отправлен на утилизацию · `UTILIZED` утилизирован · `WRITTEN_OFF` списан · `LOOKING_FOR` разыскивается.

### Метод доставки
`FORMING` создаётся · `ACTIVE` активен, можно создавать отправления · `CLOSE` удалён · `BLOCKED` заблокирован · `ERROR` ошибка создания.

### Грузоместо (доверительная приёмка, в спеке закомментировано, появится позже)
`CREATED`, `FORMING`, `FORMED`, `ACCEPTED`, `FINISHED`, `CANCELING`, `CANCELED`, `IN_CONTAINER`, `DRIVER_PICK_UP`.

---

## 4. Коды ошибок внутри 200

### `delivery-point/check-availability`
| Код | Значение |
|---|---|
| `DPRE` | пункт не подходит под параметры заказа |
| `SPE` | пункт выдачи совпадает с точкой отгрузки |
| `DPNF` | не найдена информация по пункту |
| `DAE` | не удалось рассчитать доставку до пункта |
| `OE` | не удалось рассчитать доставку |

### `order/checkout` и `order/create`
| Код | Значение |
|---|---|
| `RE` | невозможно доставить заказ получателю |
| `DAE` | не удалось рассчитать доставку до точки |
| `DCE` | ошибка предрасчёта стоимости доставки или страховки |
| `DPNF` | не найдена информация о точке доставки |
| `SDPE` | точка доставки совпадает с точкой отгрузки |
| `DPRE` | точка не подходит под параметры заказа |
| `PE` | кабинет продавца заблокирован |
| `OE` | не удалось рассчитать доставку |

### `posting/approve`
| Код | Значение |
|---|---|
| `NEB` | недостаточный баланс |
| `PNF` | отправление не найдено |
| `OE` | не удалось подтвердить |

---

## 5. Сквозной сценарий

**Подготовка в кабинете, без API.** Раздел «Методы доставки» → создать метод (как и куда передаёшь отправления). Методов может быть несколько с разными точками отгрузки. Номер сгенерированного штрихкода = `shipment_method_id`. Каждое отправление привязывается к одному методу.

**Оформление**
1. Покупатель авторизован на сайте продавца.
2. `delivery/check-client` по телефону.
3. `delivery/location` — примерный срок, пока товар не выбран (опционально).
4. Покупатель выбирает тип доставки: ПВЗ или курьер.
5. Для ПВЗ: `delivery-point/list` → `delivery-point/info` → своя фильтрация и карта → `delivery-point/check-availability` под текущую корзину.
6. `order/checkout` — стоимость, страховка, срок, cutoff.
7. Покупатель оформляет заказ.
8. Повторный `order/checkout`, затем `order/create` с `Idempotency-Key`.

**Отгрузка**
1. `posting/approve`.
2. Ozon проверяет баланс кабинета.
3. `posting/info` — результат, ждём `READY_FOR_SHIPPING`.
4. `posting/label` — этикетка, обязательна для приёмки.

**Сопровождение**
`posting/search`, `posting/info`, `posting/status-history`. Отмена: `posting/cancel` + проверка через `posting/info`. Дальше движение отменённого отправления через методы возвратов.

**Возвраты**
`return/search`, `return/info`, `return/status-history`, `return/download_barcode`, при необходимости `return/reset_barcode`.

---

## 6. Единицы и типы, на которых легко ошибиться

- Вес — **граммы** (`weight_g`), габариты — **миллиметры** (`length_mm`, `width_mm`, `height_mm`). WooCommerce хранит в своих единицах, конвертация обязана быть в одном месте.
- Деньги — объект `{ amount: string, currency_code: string }`. `amount` **строка**, не число. Никакой float-арифметики.
- `request_id` — integer, твоя нумерация отправлений в пределах одного запроса. По нему сопоставляются результаты и ошибки.
- `order_external_id` / `posting_external_id` — твои идентификаторы. Туда кладём номер заказа WooCommerce.
- Даты и `cutoff_at` — строки в формате из спеки, проверить на живом ответе.
- Вебхуков нет. Статусы только опросом.
