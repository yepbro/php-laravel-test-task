# Принятые решения

Журнал технических решений и отклонений от исходного текста ТЗ.
Каждая запись привязана к задаче, в которой решение принято; файл пополняется
по ходу работ. Обоснования на уровне «почему» — здесь, детали реализации —
в коде и `docs/`.

## Окружение (Sail)

- **Порты окружения приведены к фактической конфигурации** (T-002, решение
  пользователя). Приложение публикуется на `80` (`APP_PORT`), MySQL — на
  `3306` (`FORWARD_DB_PORT`), Redis — через `FORWARD_REDIS_PORT` (по
  умолчанию `6379`; в локальном `.env` может быть переопределён, например на
  `6380` — это нормальная ситуация). Исходные значения ТЗ v1.4 (8080/3307/без
  публикации порта Redis) заменены; ТЗ и план обновлены синхронно
  (docs-коммит `50d4ced`), рассинхронизации с `compose.yaml` по портам нет.
- **Redis закреплён на образе `redis:7-alpine`** (T-002). Плавающий тег
  `redis:alpine` фактически разворачивал Redis 8.x и не гарантирует
  мажорскую версию 7, требуемую ТЗ §5.2; закреплённый тег делает окружение
  воспроизводимым. Данные в volume `sail-redis` сохраняются при смене образа;
  исключение — переход вниз с 8.x: устаревший `dump.rdb` от Redis 8 (формат
  RDB 15) нечитаем для Redis 7, на дев-этапе дамп был удалён (только
  эфемерные данные, бизнес-данных в Redis на момент задачи нет).

## Задача 003. Миграции доменных таблиц

### Внешние ключи при удалении заказа

ТЗ §6.3 явно задаёт FK только для `order_items` (cascade) и
`order_notifications` (`order_id FK`); поведение при удалении заказа оставлено
на решение исполнителя.

| Таблица | Правило | Обоснование |
|---|---|---|
| `order_items.order_id` | FK → `orders.id`, `ON DELETE CASCADE` | Прямо указано в §6.3 |
| `order_status_history.order_id` | FK → `orders.id`, `ON DELETE CASCADE` | История — производные данные заказа; задача 008 ожидает cascade наравне с позициями |
| `order_notifications.order_id` | FK → `orders.id`, `ON DELETE CASCADE` | Outbox-запись создаётся в одной транзакции с заказом (FR-MAIL-01), заказ всегда существует; уведомления — часть жизненного цикла заказа. Приложение заказы не удаляет, правило актуально только для ручных операций |
| `webhook_events.order_id` | **Без FK**, обычный индекс | По алгоритму §7 (шаг 13a) событие вставляется в `webhook_events` **до** создания/обновления заказа: для первого снимка заказа строки в `orders` ещё нет, FK нарушался бы. Кроме того, события неизвестных типов хранятся с `order_id = NULL`. Журнал событий — независимый аудит, связь остаётся мягкой ссылкой; при удалении заказа строки событий сохраняются |

### Длины и типы полей, не заданные ТЗ явно

- `webhook_events.type` — `VARCHAR(32)`: известные типы ≤ 15 символов, запас
  на расширение списка типов без смены схемы.
- `orders.status`, `order_status_history.status`,
  `webhook_events.outcome`, `order_notifications.type/status` —
  `VARCHAR(16)`: максимальное значение (`cancelled`/`rejected`) = 9 символов.
- `webhook_events.reject_reason`, `order_notifications.skip_reason` —
  `VARCHAR(32)`: `terminal_status`/`duplicate_tracking`/`invalid_recipient`.
- `orders.shipment_carrier`, `orders.shipment_tracking_number`,
  `order_items.sku` — `VARCHAR(64)`. Длина трек-номера согласована с
  `dedupe_key VARCHAR(191)`: `{order_id ≤ 64}:shipped:{tracking_number ≤ 64}`
  даёт максимум 137 < 191 байт — ключ всегда помещается.
- `orders.shipment_tracking_url` — `VARCHAR(512)`: URL заметно длиннее
  артикулов и трек-номеров.
- `webhook_events.signature_timestamp` — знаковый `INT` (Unix-секунды), как в
  ТЗ; `deliveries INT NOT NULL DEFAULT 1` и `order_notifications.attempts INT
  UNSIGNED NOT NULL DEFAULT 0` — счётчики.
- `order_items.position` — знаковый `SMALLINT`, `quantity` — `INT UNSIGNED`:
  буквальное следование §6.3.
- `cancel_reason`, `last_error` — `TEXT NULL` (свободный текст
  причины/ошибки).

### Системные временные метки

- `created_at`/`updated_at` (Eloquent `timestamps()`) — только в `orders`
  (§6.3: «техн.») и `order_notifications` (§6.3: «timestamps»). В
  `order_items`, `order_status_history` и `webhook_events` системных меток
  нет — ТЗ их не указывает; у событий журнала собственное время
  `received_at`/`last_received_at`/`processed_at` (DATETIME, UTC).
- Все доменные даты — `DATETIME` (точность 0), хранение в UTC; суммы — только
  `DECIMAL(12,2)`, плавающих типов в схеме нет (проверяется тестом).

### Индексы

Индексы по §6.3: `orders(number)`, `orders(status, placed_at)`,
`orders(customer_email_normalized, placed_at)`, `orders(placed_at, id)`,
уники `order_items(order_id, position)`,
`order_status_history(order_id, status, at)` + индекс `(order_id, at, id)`,
`order_notifications(dedupe_key)`. Сверх ТЗ добавлен один индекс
`webhook_events(order_id)` (решение исполнителя) — для выборки событий
конкретного заказа в отладке и админке; §6.3 для `webhook_events` задаёт
только PK. Служебные индексы под будущие задачи (sweeper зависших
`pending`, retention-очистка `webhook_events`) не добавляются превентивно —
их добавит соответствующая задача, если профиль нагрузки этого потребует.

### Очереди

`jobs`/`job_batches`/`failed_jobs` берутся из стандартной миграции
`0001_01_01_000002_create_jobs_table.php` — не дублируются (проверяется
тестом).

## Задача 004. PHP Enums домена

### Состав enum'ов и соответствие БД/API

`App\Enums\{OrderStatus, NotificationType, NotificationStatus, EventOutcome}`
— string-backed, строковые значения дословно из ТЗ и совпадают со значениями
в колонках VARCHAR(16) из задачи T-003 и в API: `created|paid|shipped|
delivered|cancelled`, `paid|shipped|cancelled`, `pending|sending|sent|
skipped|failed`, `applied|stale|ignored|rejected`. Состав и значения
зафиксированы unit-тестами (включая проверку `from()` → `ValueError` /
`tryFrom()` → null на несуществующих значениях — приведение типа ничего
не маскирует).

### Ранги OrderStatus для сортировки истории

ТЗ (FR-HIS-03/FR-HIS-04) фиксирует ранги только не-терминальных статусов:
`created=0, paid=1, shipped=2`. Ранги `delivered=3` и `cancelled=4` —
решение исполнителя по смыслу сортировки истории: прогресс
`shipped → delivered` не должен считаться откатом, а при равном моменте
`at` порядок записей должен быть детерминированным (`created, paid,
shipped, delivered, cancelled`). Ранг используется только в механике
истории статусов — сортировка при равном `at` (FR-HIS-04) и обнаружение
отката для записи факта в историю (FR-HIS-03) — и не является
универсальным правилом допустимости переходов; переходы реализуются
сервисами по FR-ORD-05/FR-ORD-09.

### Терминальные статусы

Набор терминальных статусов Q35 (`delivered`, `cancelled`) пока
захардкожен в `OrderStatus::isTerminal()` с PHPDoc-ссылкой на Q35;
`config('marketplace.terminal_statuses')` появится в задаче T-009,
после чего сервисы переходов будут читать его. Чтобы не плодить два
источника истины, в T-009 связать их: либо `isTerminal()` переводится
на чтение конфига, либо конфиг заполняется case'ами enum'а с
тестом-сверкой (выбор — за задачей T-009).

### `duplicate` не входит в EventOutcome

По FR-ORD-01/FR-ORD-08 `duplicate` — только HTTP-ответ повторной
доставке; исходный `outcome` события в журнале при дубле не заменяется.
Поэтому `duplicate` — не case `EventOutcome` (зафиксировано тестом).
Словарь HTTP-ответа (FR-WH-10: `processed|duplicate|stale|ignored|rejected`)
шире журнала: `applied` отдаётся наружу как `processed`; маппинг —
задача T-010 (отмечено в PHPDoc enum).
