# Тесты: запуск, окружение и структура

> Дата актуализации: 2026-10-27. Каркас (T-014): команды запуска,
> окружение и карта существующих наборов. Матрица сценариев
> S-01…S-39 ↔ реальные тесты появится в T-020 (красный baseline)
> и будет пополняться задачами реализации (DOC-02). Все команды —
> внутри Sail-контейнеров (AGENTS.md).

## Содержание

- [Как запускать](#как-запускать)
- [Тестовое окружение](#тестовое-окружение)
- [Структура тестов](#структура-тестов)
- [Помощники tests/Support (T-015, T-018)](#помощники-tests-support-t-015-t-018)
- [Интеграционные P1-тесты и Mailpit (план)](#интеграционные-p1-тесты-и-mailpit-план)
- [Особые правила](#особые-правила)

## Как запускать

`sail` — сокращение для `./vendor/bin/sail`.

| Команда | Что делает |
|---|---|
| `sail composer check` | единая точка качества: Pint (`--test`) → PHPStan (Larastan level 10) → PHPUnit (Paratest) → `vue-tsc` (§5.1/NFR-15) |
| `sail composer max` | `min` + тесты: полный прогон (стиль, типы, линт, Paratest) |
| `sail composer min` | быстрая проверка без тестов: back (Pint + PHPStan) + front (Prettier, vue-tsc, Stylelint, hex-линт, ESLint) |
| `sail composer tests` | только тесты, Paratest (параллельно) |
| `sail composer test` | тесты последовательно (один процесс) |
| `sail artisan test --filter=ИмяТеста` | точечный класс/метод |

Примеры точечного запуска:

```bash
sail artisan test --filter=DomainSchemaTest
sail artisan test --filter=MarketplaceConfigTest
```

Сборка фронтенда (`vite build`, FR-ADM-07): `sail composer build`
(обёртка над `npm run build`).

## Тестовое окружение

- **БД — только MySQL 8.4, не SQLite** (§11 ТЗ): значимы `SELECT … FOR
  UPDATE`, уникальные индексы и семантика `DATETIME`. Тестовая база
  `testing` создаётся автоматически init-скриптом compose
  (`create-testing-database.sh`); настройки — `phpunit.xml`
  (`DB_DATABASE=testing`).
- **Paratest** (`artisan test -p`, флаг `-p`): Laravel Parallel Testing
  создаёт per-process базы (`testing_test_N`) для тестов с DB-трейтами —
  обычные DB-тесты изолированы автоматически.
- Кэш/сессии/почта/очередь в тестах подменены (`CACHE_STORE=array`,
  `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`) —
  `phpunit.xml`.
- **Время** в тестах, зависящих от timestamp вебхука, замораживается
  (`travelTo`) — тестовые помощники подписанных запросов появляются
  в T-015; эталонная подпись `signature-example.md` проходит окно ±5 мин
  только при замороженном времени (§3.1 ТЗ).
- **Глобальные защиты** в `setUp()` базового `Tests\TestCase` (для всего
  сьюта): `Http::preventStrayRequests()` (исходящий HTTP без фейка падает
  сразу), `Sleep::fake(syncWithCarbon: true)` (sleep retry/backoff не
  тратит реальное время, замороженное время двигается на длительность
  sleep), `Exceptions::fake()` (report() пишется в фейк, рендер ответов
  401/422/500 не меняется).

## Структура тестов

Карта существующих наборов (по задачам, создавшим их):

| Набор | Файлы | Покрывает |
|---|---|---|
| Enums | `tests/Unit/Enums/*` | составы case'ов, `from`/`tryFrom`, ранги истории, терминальные статусы, переходы статусов уведомлений (T-004, T-012) |
| DTO / Data | `tests/Unit/Data/*` | конверт события, снимок, версия, факты истории (T-009) |
| Конфигурация | `tests/Unit/Config/*` | `config/marketplace.php` (T-009), `config/app.php` (T-014) |
| Доменные правила | `tests/Unit/Support/*` | скелетные контракты `Money`, `NotificationDedupeKey` и др. (T-006, T-009, T-012); красные поведенческие наборы T-016: версии снимков (`SnapshotVersionComparatorTest`), `Money`/subtotal, факты истории (`HistoryBuilderTest`), матрица писем (`NotificationPolicyTest`) — зелёные после T-022/T-023/T-030/T-031 |
| Вебхук (unit) | `tests/Unit/Http/*`, `tests/Unit/Actions/*`, `tests/Unit/Exceptions/*` | middleware подписи (заголовки/окно), маппер ответов, скелет Action (T-009–T-010); будущее поведение сверки HMAC — `VerifyMarketplaceSignatureHmacTest` (T-016, красный до T-024) |
| Уведомления (unit) | `tests/Unit/Jobs/*`, `tests/Unit/Mail/*` | скелет job отправки, темы писем, dedupe-ключ (T-012) |
| Схема и генерация БД | `tests/Feature/{DomainSchemaTest,DomainModelsTest,DomainFactoriesTest,DatabaseReproducibilityTest,DemoOrdersSeederTest}.php` | DDL/индексы/FK, модели, фабрики, полный цикл migrate↔rollback, демо-сидер (T-003–T-008) |
| API заказов | `tests/Feature/Api/*` | маршруты по контракту, список/карточка, 422 параметров (T-011); красный фильтр email (T-018, до T-036), контрактная валидация по `task/openapi.yaml` и края карточки/списка — S-24 «страница за пределами», nullable-поля, одинаковые SKU/USD, JSON без Accept (T-018) |
| Админка | `tests/Feature/Admin/AdminOrdersPagesTest.php` | Inertia-оболочки, вспомогательные props, `/` → `/admin/orders` (T-013) |
| Вебхук (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhookRouteTest.php` | маршрут, middleware, маппер ответов (T-010) |
| Вебхук (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhook{Security,PayloadErrors,PayloadLimit,IgnoredEvents,DuplicateDelivery,SnapshotApplication,OrderLock}Test.php` | красные HTTP-сценарии T-017: 401/400/413/422/503/200-исходы, отсутствие побочных эффектов, идемпотентность, версии/терминальные статусы (S-01…S-07, S-12…S-14, S-16…S-19, S-27, S-29…S-31, S-36…S-37, S-39); сводная карта — в `DECISIONS.md` (задача 017) |
| Вебхук: outbox и конкуренция (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhook{OutboxTransaction,OutboxCommit,CompetingVersions}Test.php` | красные сценарии T-019: транзакционный outbox (rollback уносит заказ+outbox+журнал; постановка после processed, письмо не в запросе; 422 без постановки), «нет постановки до commit» (DatabaseMigrations, реальный database queue-драйвер), конкурирующие версии S-20 (tie по кортежу, оба порядка доставки) и дедупликация shipped по треку S-11 — до T-021/T-023/T-026/T-028/T-032 |
| Хелперы вебхуков | `tests/Support/*`, `tests/Feature/Webhook/{MarketplaceExamples,SignedWebhook,MutatedWebhookBody}Test.php` | читатель сырых примеров, подпись/отправка запросов, билдеры мутантов (T-015) |
| Консоль/очередь | `tests/Feature/Console/*`, `tests/Feature/Jobs/*` | sweeper, prune, регистрация расписания, скелет job (T-012) |
| Письма: содержимое (feature, красные) | `tests/Feature/Mail/OrderMailContentTest.php` | красные тексты T-019 (до T-033): пример §6.5 `paid` дословно, суммы+валюта (A-26), `shipped` — служба/трек/ссылка, `cancelled` — причина/«Причина не указана» и строка возврата только при `paid_at ≠ null`, plain-text-only |
| Отправка писем: механика (feature, красные) | `tests/Feature/Jobs/SendOrderNotification{Delivery,Retry}Test.php` | красная механика T-019 (до T-034): happy-path `sent` всех трёх типов (адрес/тема/`sent_at`/`attempts`), получатель из текущего состояния, 9 запрещённых ячеек матрицы §6.5 → `skipped(superseded)`+`dedupe_key=NULL`, `invalid_recipient`/`no_shipment`, пара S-38 (dedupe-ключ следует текущему треку / занятый ключ → `skipped(duplicate_tracking)`), retry-контракты FR-MAIL-05/S-21 (временная ошибка — реальный transport с `TransportException`) |

Красные сценарные наборы (S-01…S-39: подпись, идемпотентность, письма,
конкурентность) — задачи T-016…T-020; контрактные тесты по
`task/openapi.yaml` — T-018/T-038; браузерные smoke-тесты Playwright —
T-047. Красные unit-наборы доменных правил T-016 падают только на
LogicException-заглушках скелета (T-022/T-023/T-024/T-030/T-031) или
на утверждениях о будущем поведении — полная карта ожиданий
«зелёное/красное» в `DECISIONS.md` (задача 016); позеленение
происходит без правки тестов.

Красные HTTP-наборы вебхуков T-017 (см. таблицу выше) устроены так же:
полный путь через настоящий маршрут с подписанными запросами
(`postSignedWebhook`, сырые примеры/мутанты T-015), замороженное время
`travelTo`, секрет через `config`, состояние БД — фабриками; падают
только утверждениями о будущем поведении (фактический ответ сейчас —
500 от заглушек T-021/T-024/T-025/T-026/T-027/T-023+). Один зелёный
якорь: просроченный timestamp при валидной подписи → 401 без записей
(окно реализовано в T-010). Тела 413/503 не пиннятся дословно
(форма — решение T-025/T-027).

Красные API-наборы T-018 — единственный красный срез API: фильтр
email (`OrdersListEmailFilterApiTest`, 3 теста; фактический ответ —
500 LogicException-заглушки `OrderListQuery`, задача T-036).
Контрактные наборы T-018 (`OrdersListContractApiTest`,
`OrderShowContractApiTest`, 7 тестов) зелёные с первого запуска:
ответы T-011 соответствуют `task/openapi.yaml`, расхождений
контракта не найдено (проверки — через `tests/Support/OpenApiSchema.php`,
см. ниже).

Красные наборы T-019 (письма/очередь/конкуренция, см. таблицу выше)
устроены так же: падают только LogicException-заглушками
(T-033/T-034 — тексты и механика отправки; T-021/T-023/T-026/T-028/
T-032 — применение события и outbox) либо утверждениями о будущем
поведении; темы писем, backoff/afterCommit, идемпотентность шага 1
задания и sweeper уже покрыты зелёными наборами T-012 и не
дублируются. Состояние после задачи: **456 тестов = 321 зелёный +
135 красных** (карта — в `DECISIONS.md`, задачи 017–019); все
красные зеленеют с реализацией владельцев без правки тестов.

## Помощники tests/Support (T-015, T-018)

Инфраструктура для HTTP-тестов вебхука (T-016–019) и контрактной
валидации API (T-018), живёт в `tests/Support/` (namespace
`Tests\Support`, автозагрузка PSR-4 `Tests\`):

| Класс | Назначение |
|---|---|
| `MarketplaceExamples` | сырые байты `task/examples/<имя>.json` (`raw('order-paid')`), байт в байт, без нормализации; `task/` только читается |
| `WebhookSignature` | подпись `hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret)` и заголовок `sha256=<hex>` — дословно как `send-event.php`; самопроверяется по эталону `signature-example.md` |
| `SignedWebhook` | подписанный запрос: `forBody($raw)` / сокращение `forExample('order-paid')`; дефолт timestamp — из замороженного `travelTo`-времени, секрет — `config('marketplace.webhook_secret')`; `X-Webhook-Id` из `body.id`; негативные варианты — `withHeader()`/`withoutHeader()` (регистр имени не важен) |
| `MutatedWebhookBody` | мутанты примеров: `withType('order.refunded')`, `withStatus('returned')`, `withUpdatedAt(iso)`; пересериализация — мутанты не байт-в-байт (решение в `DECISIONS.md`, задача 015) |
| `PostsSignedWebhooks` (trait) | `postSignedWebhook($request)` — POST телом-строкой через `call($content)`: байты (включая финальный `\n`) доставляются без перекодировки |
| `AssertsNoWebhookSideEffects` (trait) | «побочных эффектов нет»: все пять доменных таблиц пусты (для ошибочных сценариев T-017 до подготовки данных) |
| `OpenApiSchema` | контрактная валидация ответов API по `task/openapi.yaml` (T-018, FR-API-10): парсит контракт (Symfony Yaml → JSON Schema 2020-12), валидирует тело ответа валидатором `opis/json-schema` против схемы ответа по JSON-указателю (`LIST_RESPONSE`/`ORDER_RESPONSE` — инлайн-схемы `paths`, включая конверт data/links/meta); при расхождении падает полным деревом ошибок валидатора с телом ответа; `task/` только читается |
| `RestoresEnvironment` (trait) | изоляция env-мутаций конфиг-тестов: `withEnv(['KEY' => value\|null], $scope)` временно меняет переменные Env-репозитория процесса и гарантированно восстанавливает исходные значения в `finally` — без этого `set()/clear()` из теста виден всем последующим тестам процесса (репозиторий один, и Laravel грузит `.env` через него же) |

Паттерн времени в тестах: `travelTo(...)` замораживает «сейчас», и
дефолтный timestamp подписи берётся из него; эталонные timestamp и
секрет примера подписи всегда задаются явно.

## Интеграционные P1-тесты и Mailpit (план)

> Статус: план (T-019). Реализация — за **T-034** (появляется механика
> отправки: процессные сценарии очереди/писем становятся исполнимыми)
> и **T-046** (финальная приёмка). Требование §11 ТЗ: конкурентность —
> P1, интеграционный тест с Mailpit — P1 (опционально). Наборы ниже
> **не входят** в phpunit-сьют (`sail composer tests`): зависят от
> поднятого стенда и живого воркера; `markTestSkipped` не используется
> (правило завершённых задач) — запуск отдельной командой.

### 1. Параллельная доставка одного события (S-04, FR-ORD-01)

Против поднятого стенда (Sail, приложение `:80`): N параллельных POST
одного `event.id` (разные timestamp/подписи) процессами
`Symfony\Process` (примеры `task/examples` + `send-event.php`-подпись,
как в тест-хелперах T-015, но настоящими HTTP-запросами).

Проверяется: ровно одна строка `webhook_events` (`deliveries = N`),
ровно одно письмо, заказ применён один раз; ответы: один
`200 processed`, остальные `200 duplicate` (или 503 по блокировке —
без потери данных). Отдельный под-сценарий: два параллельных снимка
одного заказа (конкурирующие версии) — финальная версия = более
свежая по кортежу, обе строки журнала на месте, писем — по переходам.

### 2. Воркер + реальная очередь + Mailpit (S-28/S-35)

Поднятый Redis-воркер (`sail artisan queue:work redis` или Horizon) +
Mailpit (SMTP `1025`, API/UI `8025`):

- отправить подписанное событие (`php task/examples/send-event.php` —
  см. README, раздел «Тестовое событие»);
- дождаться обработки воркером (poll `order_notifications.status`
  до `sent`);
- прочитать письмо через Mailpit API `GET :8025/api/v1/messages`:
  получатель = `customer.email`, тема дословно из §6.5 (символ «№»
  U+2116), тело — plain text UTF-8 с составом/суммами;
- сценарий NFR-05: остановить Mailpit/воркер → временная ошибка →
  `pending` с `attempts++` → восстановление → `sent` (S-21 в бою);
- зависший `sending` (убить воркер в окне отправки) — sweeper
  не переотправляет, запись видна ручной процедуре из
  `docs/operations.md`.

Команды запуска (для T-034/T-046 зафиксировать точные):

```bash
sail up -d                      # стенд: приложение, MySQL, Redis, Mailpit
sail artisan queue:work redis & # воркер (или sail artisan horizon)
php task/examples/send-event.php order-paid   # подписанное событие
curl -s http://localhost:8025/api/v1/messages | jq '.messages[].Subject'
```

### 3. Ручной путь через Mailpit (доступен уже сейчас)

1. `sail artisan migrate:fresh --seed` — демо-данные (T-007);
2. отправить тестовое событие — см. README «Тестовое событие»
   (до T-024 ответ 500 — честная заглушка сверки подписи);
3. открыть Mailpit UI `http://localhost:8025` — письма появятся
   после реализации T-032/T-034 (outbox → очередь → SMTP Mailpit).

## Особые правила

- **DDL-тест один** (`DatabaseReproducibilityTest`, решение T-008):
  единственный тестовый класс, работающий с БД **без** DB-трейтов
  (управляет схемой artisan-командами). Новые artisan-DDL-сценарии
  добавляются внутрь него (выполняются последовательно в одном
  процессе); причина — Paratest создаёт per-process базы только для
  тестов с трейтами, безтрейтовые DDL-сценарии конкурируют за базовую
  `testing`. Обычные DB-тесты — только с DB-трейтами
  (`RefreshDatabase`).
- **Исключение для afterCommit-наблюдения** (T-019):
  `MarketplaceWebhookOutboxCommitTest` использует `DatabaseMigrations`
  вместо `RefreshDatabase` — корневая транзакция теста обязана
  коммититься внутри теста, иначе afterCommit-постановка (FR-MAIL-01)
  не наблюдаема (обоснование и отклонённые альтернативы —
  `DECISIONS.md`, задача 019). DDL-правило выше это не затрагивает:
  трейт стандартный, схему не пересоздаёт artisan-командами.
- Тесты не зависят от сидеров: данные создаются фабриками
  (`SEED_DEMO_ORDERS` влияет только на локальный `db:seed`).
- Входные примеры `task/examples/*.json` в тестах читаются как сырые
  байты, без пересериализации (`MarketplaceExamples`, T-015; `task/`
  не изменяется). Изменение полей примера — через `MutatedWebhookBody`
  (пересериализация мутантов зафиксирована в `DECISIONS.md`);
  побайтовые искажения для негативных проверок подписи (S-13:
  «тело изменено на один байт») — на сырых байтах с подменённой
  подписью (`withHeader`), не через мутанта.
