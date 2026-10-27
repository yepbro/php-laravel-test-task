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
| Вебхук (feature) | `tests/Feature/Webhook/MarketplaceWebhookRouteTest.php` | маршрут, middleware, маппер ответов (T-010) |
| Вебхук (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhook{Security,PayloadErrors,PayloadLimit,IgnoredEvents,DuplicateDelivery,SnapshotApplication,OrderLock}Test.php` | красные HTTP-сценарии T-017: 401/400/413/422/503/200-исходы, отсутствие побочных эффектов, идемпотентность, версии/терминальные статусы (S-01…S-07, S-12…S-14, S-16…S-19, S-27, S-29…S-31, S-36…S-37, S-39); сводная карта — в `DECISIONS.md` (задача 017) |
| Хелперы вебхуков | `tests/Support/*`, `tests/Feature/Webhook/{MarketplaceExamples,SignedWebhook,MutatedWebhookBody}Test.php` | читатель сырых примеров, подпись/отправка запросов, билдеры мутантов (T-015) |
| Консоль/очередь | `tests/Feature/Console/*`, `tests/Feature/Jobs/*` | sweeper, prune, регистрация расписания, скелет job (T-012) |

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
см. ниже). Состояние после задачи: **423 теста = 321 зелёный +
102 красных** (карта — в `DECISIONS.md`, задачи 017/018); все
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

Паттерн времени в тестах: `travelTo(...)` замораживает «сейчас», и
дефолтный timestamp подписи берётся из него; эталонные timestamp и
секрет примера подписи всегда задаются явно.

## Особые правила

- **DDL-тест один** (`DatabaseReproducibilityTest`, решение T-008):
  единственный тестовый класс, работающий с БД **без** DB-трейтов
  (управляет схемой artisan-командами). Новые artisan-DDL-сценарии
  добавляются внутрь него (выполняются последовательно в одном
  процессе); причина — Paratest создаёт per-process базы только для
  тестов с трейтами, безтрейтовые DDL-сценарии конкурируют за базовую
  `testing`. Обычные DB-тесты — только с DB-трейтами
  (`RefreshDatabase`).
- Тесты не зависят от сидеров: данные создаются фабриками
  (`SEED_DEMO_ORDERS` влияет только на локальный `db:seed`).
- Входные примеры `task/examples/*.json` в тестах читаются как сырые
  байты, без пересериализации (`MarketplaceExamples`, T-015; `task/`
  не изменяется). Изменение полей примера — через `MutatedWebhookBody`
  (пересериализация мутантов зафиксирована в `DECISIONS.md`);
  побайтовые искажения для негативных проверок подписи (S-13:
  «тело изменено на один байт») — на сырых байтах с подменённой
  подписью (`withHeader`), не через мутанта.
