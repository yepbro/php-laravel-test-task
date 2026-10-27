# Тесты: запуск, окружение и структура

> Дата актуализации: 2026-10-28. Каркас (T-014): команды запуска,
> окружение и карта существующих наборов. Сводный красный baseline
> этапа 3 — раздел «Красный baseline (T-020)»; матрица сценариев
> S-01…S-39 ↔ реальные тесты пополняется задачами реализации (DOC-02).
> Все команды — внутри Sail-контейнеров (AGENTS.md).

## Содержание

- [Как запускать](#как-запускать)
- [Тестовое окружение](#тестовое-окружение)
- [Структура тестов](#структура-тестов)
- [Красный baseline этапа 3 (T-020)](#красный-baseline-этапа-3-t-020)
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
| Доменные правила | `tests/Unit/Support/*` | `Money`/subtotal — зелёный с T-022, сравнение версий снимков — с T-023, факты истории — с T-030, матрица писем — с T-031 (`MoneyTest`, `SnapshotVersionComparatorTest`, `HistoryBuilderTest`, `NotificationPolicyTest`; скелетные тесты в `DomainRuleSkeletonsTest` перевёрнуты на реальный результат); контракты `NotificationDedupeKey` и др. (T-006, T-009, T-012) |
| Вебхук (unit) | `tests/Unit/Http/*`, `tests/Unit/Actions/*`, `tests/Unit/Exceptions/*` | middleware подписи (заголовки/окно — T-010; сверка HMAC — T-024, `VerifyMarketplaceSignatureHmacTest` зелёный), middleware лимита тела (T-025, `LimitMarketplaceWebhookBodyTest`), маппер ответов (413/503 — T-025), Action (T-009–T-010, T-021) |
| Разбор тела вебхука (unit) | `tests/Unit/Support/MarketplaceEventParserTest.php` | шаги 5–11 §7 напрямую парсером (T-021): ошибки формата 400 vs структуры 422, unknown type/status, money-форматы, смешанные пояса, warning о расхождении total; HTTP-контракты тех же правил — `PayloadErrorsTest` зелёный с T-024, `IgnoredEventsTest` зелёный с T-026 |
| Уведомления (unit) | `tests/Unit/Jobs/*`, `tests/Unit/Mail/*` | скелет job отправки, темы писем, dedupe-ключ (T-012) |
| Схема и генерация БД | `tests/Feature/{DomainSchemaTest,DomainModelsTest,DomainFactoriesTest,DatabaseReproducibilityTest,DemoOrdersSeederTest}.php` | DDL/индексы/FK, модели, фабрики, полный цикл migrate↔rollback, демо-сидер (T-003–T-008) |
| API заказов | `tests/Feature/Api/*` | маршруты по контракту, список/карточка, 422 параметров (T-011); красный фильтр email (T-018, до T-036), контрактная валидация по `task/openapi.yaml` и края карточки/списка — S-24 «страница за пределами», nullable-поля, одинаковые SKU/USD, JSON без Accept (T-018) |
| Админка | `tests/Feature/Admin/AdminOrdersPagesTest.php` | Inertia-оболочки, вспомогательные props, `/` → `/admin/orders` (T-013) |
| Вебхук (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhookRouteTest.php` | маршрут, middleware, маппер ответов (T-010) |
| Вебхук (feature) | `tests/Feature/Webhook/MarketplaceWebhook{PayloadErrors,PayloadLimit,IgnoredEvents,DuplicateDelivery,SnapshotApplication,OrderLock}Test.php` | HTTP-сценарии T-017: ошибки подписи (SecurityTest, зелёный с T-024) и payload (PayloadErrorsTest — зелёный с T-024: 400/422 парсера наблюдаемы из HTTP; PayloadLimitTest — зелёный с T-025: 413 до JSON-разбора), 200-исходы `ignored`/`duplicate` (зелёные с T-026), занятый lock заказа (OrderLockTest — зелёный с T-027: 503), применение снимка (`processed`/`stale` — с T-028, `rejected` — с T-029, история — с T-030, outbox-записи — с T-032: SnapshotApplication — 11 зелёных), версии/терминальные статусы (S-01…S-07, S-12…S-14, S-16…S-19, S-27, S-29…S-31, S-36…S-37, S-39); сводная карта — в `DECISIONS.md` (задача 017) |
| Вебхук: outbox и конкуренция (feature) | `tests/Feature/Webhook/MarketplaceWebhook{OutboxTransaction,OutboxCommit,CompetingVersions}Test.php` | сценарии T-019, зелёные с T-032: транзакционный outbox (rollback уносит заказ+outbox+журнал; постановка после processed, письмо не в запросе; 422 без постановки), «нет постановки до commit» (DatabaseMigrations, реальный database queue-драйвер), конкурирующие версии S-20 (tie по кортежу, оба порядка доставки; зелёные с T-028, повторы duplicate по FR-ORD-01 — ожидание повтора проигравшего исправлено основной сессией) и дедупликация shipped по треку S-11 (UNIQUE dedupe_key) |
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
`travelTo`, секрет через `config`, состояние БД — фабриками; с T-026
журналирование реализовано (unknown type/status → `ignored`, повторы
→ `duplicate`), с T-028 — применение снимка (`processed`/`stale`),
с T-029 — терминальные статусы (`rejected`):
оставшаяся краснота наборов упирается в следующие заглушки (T-030/
T-032 — карта после T-029 ниже). Зелёные якоря:
просроченный
timestamp при валидной подписи → 401 без записей (окно реализовано
в T-010) и весь `MarketplaceWebhookSecurityTest` (неверная подпись →
единый 401, T-024). Тела 413/503 не пиннятся дословно
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
поведением; темы писем, backoff/afterCommit, идемпотентность шага 1
задания и sweeper уже покрыты зелёными наборами T-012 и не
дублируются. Точные цифры сьюта на момент задачи — 456 тестов =
321 зелёный + 135 красных (карта — в `DECISIONS.md`, задачи 017–019);
в итоговый коммит T-019 после записи цифры вошло ещё 3 теста
(459). Все красные зеленеют с реализацией владельцев без правки
тестов; сводный перечень красноты по функциям и владельцам —
следующий раздел (T-020).

## Красный baseline этапа 3 (T-020)

> Дата: 2026-10-27. Фиксация ожидаемо красного состояния после задач
> T-016…T-019: все падения — только по функциональным причинам
> (заглушки доменного слоя и утверждения о будущем поведении),
> перечень по функциям с задачами-владельцами закрытия — контроль
> позеленения задачами этапа 4. Детальные карты наборов —
> `DECISIONS.md` (задачи 016–019); здесь — сводный baseline.

**Канонический прогон (Paratest, `sail composer tests`): 460 тестов =
325 зелёных + 135 красных** (52 failures — assertion о будущем
поведении; 83 errors — `LogicException`-заглушки с задачей-владельцем
в сообщении), 1512 assertions. Последовательный PHPUnit (`composer
test`) даёт то же множество красных. Детерминизм: шесть полных
прогонов с разными `--random-order-seed` — одно и то же множество из
135 красных (после починки двух инфраструктурных флаков, см. ниже);
порядок тестов на состав красноты не влияет.

### 83 errors — `LogicException`-заглушки доменных правил

| Владелец закрытия | Красных | Набор |
|---|---|---|
| T-022 | 13 | `tests/Unit/Support/MoneyTest.php` — расчёт subtotal |
| T-023 | 16 | `tests/Unit/Support/SnapshotVersionComparatorTest.php` — сравнение версий снимков |
| T-024 | 2 | `tests/Unit/Http/Middleware/VerifyMarketplaceSignatureHmacTest.php` — сверка HMAC |
| T-030 | 19 | `tests/Unit/Support/HistoryBuilderTest.php` — факты истории |
| T-031 | 15 | `tests/Unit/Support/NotificationPolicyTest.php` — матрица писем §6.5 |
| T-034 | 18 | `SendOrderNotificationDeliveryTest` (17), `SendOrderNotificationRetryTest` (1) — механика отправки |

### 52 failures — утверждения о будущем поведении

Текущий фактический ответ вебхук-маршрута — 500 от заглушек (HMAC
T-024, разбор T-021, журналирование T-026, блокировки T-027,
применение T-023/T-028/T-029), поэтому HTTP-наборы падают на первом
же утверждении о коде/теле ответа и зеленеют по мере реализации
цепочки; владелец закрытия — задача, ответственная за проверяемое
поведение (карты T-017/T-019).

| Набор (`tests/Feature/…`) | Красных | Ожидание → фактическое | Владелец |
|---|---|---|---|
| `Webhook/MarketplaceWebhookSecurityTest` | 3 | 401 без побочных эффектов → 500 | T-024 |
| `Unit/Http/Middleware/VerifyMarketplaceSignatureHmacTest` (failures) | 2 | `InvalidSignature` → прилетает заглушка T-024 | T-024 |
| `Webhook/MarketplaceWebhookPayloadErrorsTest` | 9 | 400/422 по формату конверта → 500 | T-021 |
| `Webhook/MarketplaceWebhookPayloadLimitTest` | 2 | 413 для >1 MiB → 500 | T-025 |
| `Webhook/MarketplaceWebhookIgnoredEventsTest` | 4 | 200 `ignored` для unknown type/status → 500 | T-021, T-026 |
| `Webhook/MarketplaceWebhookDuplicateDeliveryTest` | 2 | 200 `duplicate`, deliveries=2 → 500 | T-026, T-027 |
| `Webhook/MarketplaceWebhookSnapshotApplicationTest` | 11 | 200 `processed`/`stale`/`rejected` по версии и Q35 → 500 | T-023, T-026…T-032 |
| `Webhook/MarketplaceWebhookCompetingVersionsTest` | 4 | tie кортежа версий, дедупликация по треку → 500 | T-021, T-023, T-026, T-028, T-032 |
| `Webhook/MarketplaceWebhookOrderLockTest` | 1 | 503 при занятом локе → 500 | T-027 |
| `Webhook/MarketplaceWebhookOutboxTransactionTest` | 3 | outbox в одной транзакции → 500 | T-032 (через T-024→T-021→T-026) |
| `Webhook/MarketplaceWebhookOutboxCommitTest` | 1 | постановка после commit → 500 | T-032 |
| `Api/OrdersListEmailFilterApiTest` | 3 | фильтр email без учёта регистра → 500 заглушки `OrderListQuery` | T-036 |
| `Mail/OrderMailContentTest` | 5 | дословные строки писем §6.5 отсутствуют в blade-каркасах | T-033 |
| `Jobs/SendOrderNotificationRetryTest` (failures) | 2 | attempts++/`failed` не выполняются заглушкой шага 2 | T-034 |

Все красные зеленеют реализацией владельцев **без правки тестов**
(правило этапа 3); единственные ожидаемые замены по позеленении —
скелетные тесты, перечисленные в DECISIONS.md (задача 016,
«Переворачиваемые скелетные тесты»).

### Починенные в T-020 падения вне допустимого красного

Оба воспроизводились только в полных прогонах (зависели от случайного
порядка тестов; точечно — никогда), диагностика и решения —
`DECISIONS.md` (задача 020):

1. **Флак детерминизма демо-сида** — `DemoOrdersSeederTest::test_rerun_
   rebuilds_identical_dataset`: `Faker\Generator::__destruct()` вызывает
   `seed()` без аргумента, а тот — глобальный `mt_srand()` случайным
   сидом; при пересоздании приложения на каждом тесте старый
   Faker-генератор собирался GC в произвольной точке следующего теста
   и подменял mt-последовательность посреди цикла сида. Фикс — один
   генератор Faker на процесс (`Tests\TestCase::setUp`), деструкторов
   между тестами больше нет.
2. **Утечка сид-данных** — `DatabaseReproducibilityTest` (единственный
   класс без DB-трейтов) завершался `migrate --seed` и оставлял
   закоммиченные 12 демо-заказов: `RefreshDatabase` мигрирует fresh
   один раз на процесс и последующие классы видели чужие строки —
   редкие флаки счётчиков (`OrdersListApiTest`, «зелёный якорь»
   `MarketplaceWebhookSecurityTest`, production-skip демо-сидера).
   Фикс — tearDown класса очищает доменные таблицы (схема остаётся).

### MySQL 8.4 — подтверждение и guard

Сьют работает только против MySQL 8.4 (образ `mysql:8.4` в compose;
фактическая версия сервера 8.4.13), драйвер `mysql`, тестовая база
`testing` (Paratest — per-process `testing_test_N`), SQLite не
используется: `phpunit.xml` задаёт `DB_DATABASE=testing` и очищает
`DB_URL`, не переопределяя `DB_CONNECTION` из `.env`. Зафиксировано
зелёным guard-тестом `tests/Feature/TestingEnvironmentTest.php`
(драйвер, имя тестовой базы, версия 8.4.x) — «негласный откат» на
SQLite уронит его, а не пройдёт молча.

### Обновление карты после T-024 (сверка HMAC)

Реализация сверки подписи (задача T-024) сняла заглушку шага 4 §7:
подписанные запросы проходят аутентификацию и доходят до парсера
(T-021) и заглушек применения. Канонический прогон (Paratest):
**529 тестов = 440 зелёных + 89 красных (37 failures + 52 errors)**,
1761 assertion (было 423 + 106). Позеленели 17 тестов, все из карты
baseline:

| Набор | Позеленело | Было → стало |
|---|---|---|
| `Unit/Http/Middleware/VerifyMarketplaceSignatureHmacTest` | 4 (2 errors + 2 failures) | эталонная подпись/чужой секрет/изменённый байт/верхний hex — без правки тестов |
| `Webhook/MarketplaceWebhookSecurityTest` | 3 failures | S-12/S-13: неверная подпись → единый 401 без побочных эффектов |
| `Webhook/MarketplaceWebhookPayloadErrorsTest` | 9 failures | 400/422 парсера наблюдаемы из HTTP (T-021+T-024) |
| `Webhook/MarketplaceWebhookOutboxTransactionTest` | 1 failure | негативный 422-тест (нет постановки) — нужны были только подпись и парсер |

Красные владельцев T-025/T-026/T-027+ не изменились в составе
(IgnoredEvents 4 — теперь 500 от заглушки журналирования T-026;
PayloadLimit 2 — фактический ответ 422/400 от парсера вместо 413;
Duplicate/Snapshot/Competing/OrderLock/Outbox — 500 от заглушек
применения). Пиннившие заглушку T-024 зелёные скелетные тесты
перевёрнуты по правилу задачи 016 (см. DECISIONS.md, задача 024):
`VerifyMarketplaceSignatureTest` (±300-граница, fail-closed-свойство,
верхний hex), `MarketplaceWebhookRouteTest` (неверный HMAC → 401),
`VerifyMarketplaceSignatureHmacTest` (±299 → `$next`),
`SignedWebhookTest` (500 теперь от заглушек применения).

### Обновление карты после T-025 (лимит тела и HTTP-ответы)

Реализация лимита 1 MiB и полная таблица кодов маппера (задача T-025)
позеленели владельца PayloadLimitTest. Канонический прогон (Paratest):
**545 тестов = 458 зелёных + 87 красных (35 failures + 52 errors)**,
1816 assertion (было 529 = 440 + 89). Изменения против T-024:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookPayloadLimitTest` | 2 failures позеленели без правки: 413 до JSON-разбора (middleware `LimitMarketplaceWebhookBody` до подписи) |
| `Unit/Http/Middleware/LimitMarketplaceWebhookBodyTest` | +10 новых (T-025): границы 1 MiB, Content-Length/фактический размер, мусорный заголовок |
| `Unit/Http/Responses/WebhookResponseMapperTest` | +2 новых: PayloadTooLarge → 413, WebhookUnavailable → 503 |
| `Webhook/MarketplaceWebhookExceptionReportingTest` | +3 новых (T-025): `ShouldntReport` — 401/400/413 не репортятся |
| `Webhook/MarketplaceWebhookRouteTest` | +1 новый (по ревью P3): порядок middleware — oversized без валидной подписи → 413, не 401 |

Состав красных владельцев T-026…T-036 поэлементно совпадает с картой
после T-024 (35 failures + 52 errors), ни один зелёный не упал.
Граница «ровно 1 MiB допустимо» пиннится unit-тестом middleware
(решение T-017), не HTTP-набором.

### Обновление карты после T-026 (журнал событий и идемпотентность)

Реализация журналирования и идемпотентности по `event.id` (задача
T-026, шаги 8/10/13a §7: идемпотентная вставка `webhook_events`,
duplicate-ветка `deliveries++`/`last_received_at`, warning
`duplicate_payload_mismatch` без содержимого payload, исходы `ignored`)
позеленела двух владельцев карты. Канонический прогон (Paratest):
**546 тестов = 464 зелёных + 82 красных (30 failures + 52 errors)**,
1873 assertion (было 545 = 458 + 87, из них 35 failures). Изменения
против T-025:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookIgnoredEventsTest` | 4 failures позеленели без правки: 200 `ignored` + журнал для unknown type/status, повтор → `duplicate` (S-18/S-36) |
| `Webhook/MarketplaceWebhookDuplicateDeliveryTest` | 1 из 2 позеленел: S-02 altered-body → `duplicate`, deliveries=2, исходные payload/хеш сохранены; добавлен assert warning `duplicate_payload_mismatch` (остаток T-017) |
| `Unit/Actions/ProcessMarketplaceEventTest` | 2 скелетных теста перевёрнуты по правилу задачи 016 (заглушки T-026 → реальное поведение: `ignored` + поля строки журнала, payload байт в байт с `\n`); +1 новый (откат транзакции применения уносит вставку журнала, FR-ORD-01); класс переведён на RefreshDatabase (Action пишет в БД) |

Оставшиеся красные владельцев T-027+ не изменились в составе (30
failures + 52 errors, каждый — своя заглушка): DuplicateDelivery
sequential (S-03: нужен честный `processed` первой доставки —
T-027+/T-028), Snapshot 11 / Competing 4 / OrderLock 1 / Outbox
Transaction 2 + Commit 1 (применение T-027…T-032; вставка журнала
откатывается вместе с транзакцией — наблюдаемое поведение 500 без
побочных эффектов не изменилось), Mail 5 / Jobs Delivery 17 + Retry 3
(T-033/T-034), History 19 (T-030), Policy 15 (T-031), email-фильтр 3
(T-036). Warning-логи T-026 (`unknown_type`/`unknown_status`/
`duplicate_payload_mismatch`) ассертятся в позеленевших наборах
(остаток задачи 017 закрыт).

### Обновление карты после T-027 (блокировки и транзакционный обработчик)

Реализация Redis-lock `order:{id}` c ожиданием и транзакции с
`SELECT … FOR UPDATE` + deadlock-ретраями (задача T-027, шаги 12–13
§7, FR-ORD-07) позеленела владельца OrderLockTest. Канонический
прогон (Paratest): **548 тестов = 467 зелёных + 81 красный
(29 failures + 52 errors)**, 1883 assertion (было 546 = 464 + 82,
из них 30 failures). Изменения против T-026:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookOrderLockTest` | 1 failure позеленел без правки: занятый стороной lock `order:{id}` → 503 `temporarily_unavailable` (JSON, машинный код), побочных эффектов нет — вставка журнала находится внутри контура блокировки и не выполняется |
| `Unit/Actions/ProcessMarketplaceEventTest` | +2 новых (T-027): занятый lock → `WebhookUnavailable` без записи журнала (Action-уровень); lock освобождается в finally при исключении заглушки применения (probe-захват сразу после 500) |

Оставшиеся красные не изменились в составе (29 failures + 52
errors, каждый — своя заглушка): DuplicateDelivery sequential 1
(S-03: честный `processed` первой доставки — T-028), Snapshot 11 /
Competing 4 / Outbox 3 (применение T-028…T-032; вставка журнала
по-прежнему откатывается вместе с транзакцией — наблюдаемое 500 без
побочных эффектов не изменилось), Mail 5 / Jobs Delivery 17 +
Retry 3 (T-033/T-034), History 19 (T-030), Policy 15 (T-031),
email-фильтр 3 (T-036). Заглушка применения сохраняет сообщение
с «T-027» (каркас блокировки) — его пиннит скелетный тест набора
`ProcessMarketplaceEventTest` до T-028.

Технические детали проверки блокировок в сьюте: `CACHE_STORE=array`
(phpunit.xml), `ArrayStore` реализует `LockProvider` — отдельный
Redis не нужен; занятый lock тест занимает вручную
`Cache::lock(...)->get()`, обработчик ждёт `block(timeout)` (в
OrderLockTest timeout обнулён конфигом — отказа мгновенный);
`Sleep::fake(syncWithCarbon)` делает ожидание детерминированным.
Истинно параллельные доставки (S-04) — интеграционный P1-план ниже.

### Обновление карты после T-028 (применение полного снимка)

Реализация применения снимка (задача T-028: шаги 13c/13e/13g §7 —
версия/stale, полная перезапись заказа и позиций включая явные null,
финализация исхода журнала `applied`/`stale` + `processed_at`)
позеленела 6 тестов владельца и позволила ряду красных дойти до
следующих заглушек. Канонический прогон (Paratest):
**550 тестов = 475 зелёных + 75 красных (21 failures + 54 errors)**,
1972 assertion (было 548 = 467 + 81, из них 29 failures). Изменения
против T-027:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookSnapshotApplicationTest` | 4 из 11 позеленели без правки: S-05 (stale после paid: статус/суммы/paid_at сохранены, items 0, журнал stale), S-19 (моменты UTC, не настенное время), S-27 (total-расхождение — processed, total дословно), S-31 (тот же терминальный статус — данные обновляются). Остальные 7 красные с новыми причинами: S-01/S-06/S-07 — пустая `order_status_history` (T-030; затем ждут и order_notifications — T-032), S-37 — отсутствие факта отката `paid@updated_at` в истории (T-030; факты фабрики `withHistory()` на месте, недостаёт именно записи отката), S-29/S-30/S-39 — фактический `processed` вместо `rejected` (13d — T-029, не реализуется в T-028 сознательно) |
| `Webhook/MarketplaceWebhookCompetingVersionsTest` | 2 из 4 позеленело: S-20 оба порядка доставки (повторы — duplicate по FR-ORD-01, включая повтор применённого проигравшего — ожидание исправлено основной сессией на верификации T-028, см. DECISIONS.md). Остальные 2 красные: S-11 × 2 — отсутствие order_notifications (T-032). Попутно исправлен механический дефект набора: три `assertDatabaseCount(…, …, 'сообщение')` передавали сообщение именем БД-соединения (латентно с T-019, стало достижимо с T-028) — заменены на эквивалентные `assertSame` |
| `Webhook/MarketplaceWebhookDuplicateDeliveryTest` | набор зелёный целиком: S-03 (последовательный повтор после честного `200 processed` первой доставки) позеленел — журнал одна строка applied/deliveries=2, заказ и позиции от первого применения |
| `Webhook/MarketplaceWebhookOutboxTransactionTest` | 2 красных (было 2): негативный 422 зелёный как был; позитивные теперь проходят `processed` и падают на отсутствии order_notifications (T-032) — rollback-тест на `assertDatabaseHas`, dispatch-тест error'ом на `sole()` |
| `Webhook/MarketplaceWebhookOutboxCommitTest` | 1 красный (был): `processed` честный, error на `sole()` — order_notifications нет (T-032) |
| `Unit/Actions/ProcessMarketplaceEventTest` | 2 скелетных теста перевёрнуты по правилу задачи 016 (заглушка применения → реальное применение: PROCESSED + журнал applied/processed_at + заказ полями MarketHub; освобождение lock при сбое — сбой инжектируется `Order::saving`); +2 новых зелёных: полная замена позиций/shipment→null/нормализация email (FR-ORD-04) и stale без изменения заказа + исход журнала stale (10 тестов) |
| `Webhook/SignedWebhookTest` | скелетный тест эталонной подписи перевёрнут: 500 заглушки → 200 `processed` + заказ создан (основа S-22); набор переведён на RefreshDatabase (теперь пишет в БД) |

Оставшиеся красные по владельцам: Snapshot 7 (T-029 ×3, T-030 ×4),
Competing 2 (T-032), Outbox 3 (T-032),
Mail 5 (T-033), Jobs 20 (T-034), History 19 (T-030), Policy 15
(T-031), email-фильтр 3 (T-036). Ни один зелёный baseline-тест не
упал; новых красных вне карты нет.

### Обновление карты после T-029 (правило терминальных статусов Q35)

Реализация шага 13d §7 (FR-ORD-09, допущение Q35): свежий снимок
с **другим** статусом при текущем `delivered`/`cancelled` отклоняется
— 200 `rejected`, заказ/история/письма не меняются, журнал —
`outcome=rejected` + `reject_reason=terminal_status` + `processed_at`,
warning; снимок с **тем же** терминальным статусом применяется как
обычный (13e); проверка идёт после версии (13c): устаревший снимок
при терминальном заказе — `stale`, не `rejected`. Канонический прогон
(Paratest): **552 теста = 481 зелёный + 71 красный (17 failures +
54 errors)**, 2000 assertion (было 550 = 476 + 74, из них 20
failures; +2 теста — новые юниты T-029). Изменения против T-028:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookSnapshotApplicationTest` | 3 из 7 красных позеленели без правки: S-29 (delivered → свежий `paid`: rejected, журнал rejected+terminal_status, заказ нетронут), S-30 (cancelled → свежий `shipped`: то же), S-39 (delivered → свежий `cancelled`: rejected по допущению Q35). Остались 4 красных — T-030 (история: S-01/S-06/S-07 пустая, S-37 — факт отката); S-31 (тот же терминальный статус — данные обновляются) зелёный как был |
| `Unit/Actions/ProcessMarketplaceEventTest` | +2 новых зелёных: reject-ветка 13d за пределами feature-полноты (REJECTED; журнал rejected/`reject_reason`/`processed_at`; позиции отклонённого снимка не создаются; якорь warning-контекста `terminal_status` без лишних полей — паттерн IgnoredEventsTest) и порядок 13c→13d (устаревший снимок при терминальном заказе — STALE, `reject_reason` NULL); набор 12 зелёных |

Оставшиеся красные по владельцам: Snapshot 4 (T-030 ×4),
Competing 2 (T-032), Outbox 3 (T-032),
Mail 5 (T-033), Jobs 20 (T-034), History 19 (T-030), Policy 15
(T-031), email-фильтр 3 (T-036). Ни один зелёный не упал; новых
красных вне карты нет.

### Обновление карты после T-030 (история статусов)

Реализация HistoryBuilder (факты FR-HIS-01/02 из снимка, факт
коррекции FR-HIS-03) и идемпотентной записи фактов в транзакции
применения (ветки 13c stale и 13e §7; `rejected`/`duplicate`/
`ignored` историю не меняют). Канонический прогон (Paratest):
**553 теста = 502 зелёных + 51 красный (16 failures + 35 errors)**
(было 552 = 481 + 71, из них 17 failures). Изменения против T-029:

| Набор | Изменение |
|---|---|
| `Unit/Support/HistoryBuilderTest` | все 19 errors позеленели без правки: родные моменты/fallback, восстановление пропущенного `paid` (S-07-основа), FR-HIS-05 на примерах, откаты/прогресс, детерминизм |
| `Unit/Support/DomainRuleSkeletonsTest` | 2 скелетных теста HistoryBuilder перевёрнуты по правилу задачи 016 (заглушки → реальный результат: факт `created@created_at`, откат `paid@updated_at`/прогресс без факта); счёт набора 5 сохранён |
| `Webhook/MarketplaceWebhookSnapshotApplicationTest` | S-37 позеленел без правки (факт отката `paid@updated_at` коррекции в истории); S-01/S-06/S-07 прошли history-утверждения и остаются красными на СЛЕДУЮЩЕЙ заглушке — `order_notifications` (строки 131/212/245, владелец T-032; карта после T-028 это предсказывала: «затем ждут и order_notifications — T-032»); S-05/S-19/S-27/S-29/S-30/S-31/S-39 зелёные как были |
| `Unit/Actions/ProcessMarketplaceEventTest` | 12 зелёных как были (применение теперь пишет историю — assertions набора не пиннят таблицу) + 1 новый зелёный по ревью T-030 (P2-1): stale-снимок дополняет историю недостающим фактом, не меняя заказ и не создавая факт отката (заказ без истории + stale order.updated → ровно один `created@created_at`; различает «не пишет»/«пишет»/«пишет откат») |

Оставшиеся красные по владельцам: Snapshot 3 (T-032 — упираются
в order_notifications), Competing 2 (T-032), Outbox 3 (T-032),
Mail 5 (T-033), Jobs 20 (T-034), Policy 15 (T-031), email-фильтр 3
(T-036). Ни один зелёный не упал; новых красных вне карты нет.

> Расхождение с ожиданием задачи: бриф T-030 ожидал «552 = 504 + 48
> (13 failures + 35 errors)», что предполагает позеленение всех 4
> Snapshot-тестов; фактически S-01/S-06/S-07 содержат утверждения
> о `order_notifications` (outbox — T-032, вне границ T-030:
> «outbox-записи/dispatch → T-032») и не могут позеленеть раньше.
> Фактический итог — 553 = 502 + 51 (16 failures + 35 errors,
> +1 новый юнит-тест по ревью), дельта против ожидания по зелёным —
> ровно эти 3 теста; errors-часть ожидания (35) совпала.

### Обновление карты после T-031 (политика допустимости писем)

Реализована матрица §6.5/A-23 в `NotificationPolicy::allows()` —
заглушка T-009 заменена явной таблицей match: `paid` →
paid/shipped/delivered, `shipped` → shipped/delivered, `cancelled` →
только cancelled, при `created` — никаких; Q28 в матрицу не входит
(правило триггера T-032, DECISIONS.md задача 016). Канонический прогон
(Paratest): **553 теста = 517 зелёных + 36 красных (16 failures +
20 errors)**, 2057 assertions (было 553 = 502 + 51, из них 35 errors)
— ожидание задачи сошлось точно. Изменения против T-030:

| Набор | Изменение |
|---|---|
| `Unit/Support/NotificationPolicyTest` | все 15 errors матрицы позеленели без правки тестов (полное декартово произведение 3 × 5 + защита полноты провайдера); набор 16 зелёных |
| `Unit/Support/DomainRuleSkeletonsTest` | скелетный тест policy перевёрнут по правилу задачи 016 (ожидание LogicException → реальный результат: диагональ `paid@paid`=true, `paid@cancelled`=false, `cancelled@cancelled`=true); счёт набора 5 сохранён |

Оставшиеся красные по владельцам: Snapshot 3 (T-032 — упираются
в order_notifications), Competing 2 (T-032), Outbox 3 (T-032),
Mail 5 (T-033), Jobs 20 (T-034), email-фильтр 3 (T-036). Ни один
зелёный не упал; новых красных вне карты нет. Заглушки LogicException
в Support-классах исчерпаны (T-022/T-023/T-030/T-031); оставшаяся
errors-краснота — шаги job `SendOrderNotification` (T-034).

### Обновление карты после T-032 (outbox-уведомления и дедупликация)

Реализованы шаги 13f/14 §7: триггер смены статуса
`NotificationTrigger` (тип из нового статуса, первый снимок —
письмо, откат Q28 и «тот же статус» — без письма), запись
`order_notifications` (pending, attempts 0, dedupe-ключ
`{order}:{type}` / `{order}:shipped:{track}` от трека
события-триггера) в транзакции применения с молчаливым пропуском
дубля `UNIQUE(dedupe_key)` (FR-MAIL-02), dispatch
`SendOrderNotification` после commit (`DB::afterCommit`,
изолирован от ответа: неудача — warning + 200 processed, pending
подберёт sweeper T-035). Канонический прогон (Paratest):
**586 тестов = 558 зелёных + 28 красных (10 failures + 18 errors)**
(было 553 = 517 + 36, из них 16 failures). Изменения против T-031:

| Набор | Изменение |
|---|---|
| `Webhook/MarketplaceWebhookSnapshotApplicationTest` | S-01/S-06/S-07 позеленели без правки (order_notifications: полный цикл paid+shipped ×2; paid первым ×1; shipped первым ×1 без paid-письма); остальные 8 зелёные как были; набор 11 зелёных |
| `Webhook/MarketplaceWebhookCompetingVersionsTest` | S-11 ×2 позеленели без правки: тот же трек после отката — письмо одно (UNIQUE dedupe_key), другой трек — вторая запись со своим ключом; S-20 зелёные как были; набор 4 зелёных |
| `Webhook/MarketplaceWebhookOutboxTransactionTest` | rollback и dispatch-тесты позеленели: заказ+outbox+журнал уходят одним откатом; ровно один `SendOrderNotification` с id записи, письмо в запросе не отправляется; негативный 422 без постановки зелёный как был. Попутно механическая правка (латентна с T-019, достижима с созданием записей; тот же класс дефекта, что правлен в CompetingVersions на T-028): `assertDatabaseCount(…, …, 'сообщение')` передавал сообщение именем БД-соединения — заменён на `assertSame` с count по образцу T-028 |
| `Webhook/MarketplaceWebhookOutboxCommitTest` | «нет постановки до commit» позеленел: до коммита корневой транзакции очередь пуста, после — ровно одно задание. Механическая правка (латентна с T-019): буквальный `assertStringContainsString(SendOrderNotification::class, payload)` несовместим с JSON-экранированием обратных слэшей в payload очереди — класс задания сверяется по декодированному `data.commandName` (интент сохранён) |
| `Unit/Support/NotificationTriggerTest` | новый набор (T-032): полная матрица переходов 6×5 (первый снимок/created/paid/shipped/delivered/cancelled × новый статус) с явными ожиданиями по §6.5/13f/Q28 + защита полноты провайдера (паттерн NotificationPolicyTest) — 31 зелёный |
| `Unit/Actions/ProcessMarketplaceEventTest` | +2 новых: контракт полей outbox-записи (pending, attempts 0, dedupe-ключи paid и shipped с треком события, source_event_id, recipient/subject null) и дубль dedupe-ключа (запись не создаётся, снимок применяется, журнал applied); набор 15 зелёных |

Оставшиеся красные по владельцам: Mail 5 (T-033), Jobs 20 (T-034,
из них 2 — RetryTest-файл, падают assertions на заглушке), email-фильтр
3 (T-036). Расклад failures/errors против ожидания задачи сместился
на 2: два владельца T-032 в базовой линии были errors (`sole()` без
записи), а не failures — после позеленения владельцев счёт
16 failures + 20 errors → 10 failures + 18 errors при том же итоге
«−8 красных»; новых красных нет, зелёная дельта +41 = 8 владельцев
+ 33 новых теста (31 триггер + 2 Action).

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
   (с T-026 неизвестный тип/статус отвечает 200 `ignored`, повтор —
   200 `duplicate`; с T-028 известное событие с валидным снимком
   применяется — 200 `processed`, устаревшая версия — 200 `stale`; письма
   из вебхука появятся после T-032/T-034);
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
- **Базовый класс unit-теста** — по потребности, а не по привычке:
  `PHPUnit\Framework\TestCase` — если тесту не нужны контейнер/конфиг
  (чистые enum'ы, DTO, Support-классы); `Tests\TestCase` — только когда
  нужен приложенный фреймворк (config(), контейнер, travelTo в связке
  с приложением). Бут приложения в каждом тесте — лишние миллисекунды
  и лишние соединения.
- Тесты не зависят от сидеров: данные создаются фабриками
  (`SEED_DEMO_ORDERS` влияет только на локальный `db:seed`).
- Входные примеры `task/examples/*.json` в тестах читаются как сырые
  байты, без пересериализации (`MarketplaceExamples`, T-015; `task/`
  не изменяется). Изменение полей примера — через `MutatedWebhookBody`
  (пересериализация мутантов зафиксирована в `DECISIONS.md`);
  побайтовые искажения для негативных проверок подписи (S-13:
  «тело изменено на один байт») — на сырых байтах с подменённой
  подписью (`withHeader`), не через мутанта.
