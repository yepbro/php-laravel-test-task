# Тесты: запуск, окружение и структура

> Дата актуализации: 2026-10-28. Каркас (T-014): команды запуска,
> окружение и карта существующих наборов. Сводный красный baseline
> этапа 3 — раздел «Красный baseline (T-020)»; итоговое соответствие
> сценариев приёмки S-01…S-39 реальным тестам — раздел
> «Матрица сценариев приёмки S-01…S-39 ↔ тесты» (T-045, DOC-04);
> браузерные smoke-тесты админки — раздел
> [«Playwright»](#браузерные-smoke-тесты-админки-playwright-t-047)
> (T-047). Все команды — внутри Sail-контейнеров (AGENTS.md).

## Содержание

- [Как запускать](#как-запускать)
- [CI (GitHub Actions)](#ci-github-actions)
- [Тестовое окружение](#тестовое-окружение)
- [Структура тестов](#структура-тестов)
- [Матрица сценариев приёмки S-01…S-39 ↔ тесты](#матрица-сценариев-приёмки-s-01s-39--тесты)
- [Красный baseline этапа 3 (T-020)](#красный-baseline-этапа-3-t-020)
- [Помощники tests/Support (T-015, T-018)](#помощники-testssupport-t-015-t-018)
- [Браузерные smoke-тесты админки (Playwright, T-047)](#браузерные-smoke-тесты-админки-playwright-t-047)
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
| `sail composer e2e` | браузерные smoke-тесты админки против поднятого стенда (нужен установленный браузер — см. [раздел Playwright](#браузерные-smoke-тесты-админки-playwright-t-047)) |

Примеры точечного запуска:

```bash
sail artisan test --filter=DomainSchemaTest
sail artisan test --filter=MarketplaceConfigTest
```

Сборка фронтенда (`vite build`, FR-ADM-07): `sail composer build`
(обёртка над `npm run build`).

## CI (GitHub Actions)

Воркфлоу [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)
(T-044) запускается на push в `main` и на pull request: те же
инструменты и конфиги, что и локальные проверки, разбитые на три
параллельные джобы:

| Джоба | Шаги | Локальный эквивалент |
|---|---|---|
| `backend` | `composer pint-show` → `composer phpstan` → `composer tests` | PHP-часть `sail composer check` |
| `frontend` | `npm run format:check` → `types:check` → `lint:css` → `lint:tailwind-hex` → `lint:check` → `build` | `sail composer front` в check-режиме + `sail composer build` |
| `env-sync` | `php artisan test --filter=EnvExampleSyncTest` | та же команда через `sail` (раздел «Переменные окружения» в [operations.md](operations.md)) |

Окружение джоб:

- **MySQL 8.4** — service-контейнер (база `testing`, пользователь
  `sail`/`password` — как локально). Шаг грантов `testing%`.*
  воспроизводит init-скрипт Sail `create-testing-database.sh`
  (у service-контейнеров GitHub Actions нет volumes) — Paratest
  создаёт per-process базы `testing_test_N` от имени `sail`.
  Подключение — `127.0.0.1`: переменные джобы переопределяют `DB_HOST`
  из `.env.testing` (репозиторий dotenv immutable, `phpunit.xml` эти
  ключи не задаёт).
- **PHP 8.5 / Node 24** — версии Sail-рантайма; расширения PHP —
  обязательный список `composer check-platform-reqs` (включая
  `pcntl`/`posix` — их требует `laravel/horizon`) + `pdo_mysql` для
  тестов.
- **Redis-сервис не нужен**: `phpunit.xml` подменяет кэш/сессии/почту/
  очередь на `array`/`sync`; блокировки заказов работают через
  `ArrayStore` (см. «Обновление карты после T-027»).
- **PHP в frontend-джобе** — не для линта: vite-плагин Wayfinder при
  сборке запускает `php artisan wayfinder:generate` (`buildStart()`),
  без PHP `npm run build` падает.
- **`public/build` backend-джобе не нужен**: каталог в `.gitignore`,
  в CI отсутствует, а сьют герметичен к артефактам сборки — базовый
  `TestCase` вызывает `withoutVite()` (без этого каждый Inertia-рендер
  падал 500 на `ViteManifestNotFoundException`; фикс 28.10.2026 —
  DECISIONS.md, «Фикс CI: Inertia-страницы 500 без Vite manifest»).
  Реальную сборку проверяет frontend-джоба (`vite build`).
- `env-sync` выделяет проверку переменных окружения (FR-ENV-05, DOC-07)
  в отдельный видимый шаг CI без БД; тем же тестом закрывается и общий
  сьют джобы `backend`.

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
  401/422/500 не меняется), `withoutVite()` (сьют не зависит от
  артефактов сборки фронтенда: `@vite` рендерит пустую строку без
  поиска `public/build/manifest.json`; вернуть реальный Vite в
  отдельном тесте — `$this->withVite()`; фикс падения Inertia-страниц
  500 в CI без сборки — DECISIONS.md, «Фикс CI: Inertia-страницы 500
  без Vite manifest»).

## Структура тестов

Карта существующих наборов (по задачам, создавшим их):

| Набор | Файлы | Покрывает |
|---|---|---|
| Enums | `tests/Unit/Enums/*` | составы case'ов, `from`/`tryFrom`, ранги истории, терминальные статусы, переходы статусов уведомлений (T-004, T-012) |
| DTO / Data | `tests/Unit/Data/*` | конверт события, снимок, версия, факты истории (T-009) |
| Конфигурация | `tests/Unit/Config/*` | `config/marketplace.php` (T-009), `config/app.php` (T-014), синхронность `.env.example` ⇄ `config/*.php`/`compose.yaml`/`VITE_*`/`.env.testing` и политика секрета (T-043, `EnvExampleSyncTest`) |
| Доменные правила | `tests/Unit/Support/*` | `Money`/subtotal — зелёный с T-022, сравнение версий снимков — с T-023, факты истории — с T-030, матрица писем — с T-031 (`MoneyTest`, `SnapshotVersionComparatorTest`, `HistoryBuilderTest`, `NotificationPolicyTest`; скелетные тесты в `DomainRuleSkeletonsTest` перевёрнуты на реальный результат); контракты `NotificationDedupeKey` и др. (T-006, T-009, T-012); края формата дат API — `ApiDateTest` (T-037) |
| Вебхук (unit) | `tests/Unit/Http/*`, `tests/Unit/Actions/*`, `tests/Unit/Exceptions/*` | middleware подписи (заголовки/окно — T-010; сверка HMAC — T-024, `VerifyMarketplaceSignatureHmacTest` зелёный), middleware лимита тела (T-025, `LimitMarketplaceWebhookBodyTest`), маппер ответов (413/503 — T-025), Action (T-009–T-010, T-021) |
| Разбор тела вебхука (unit) | `tests/Unit/Support/MarketplaceEventParserTest.php` | шаги 5–11 §7 напрямую парсером (T-021): ошибки формата 400 vs структуры 422, unknown type/status, money-форматы, смешанные пояса, warning о расхождении total; HTTP-контракты тех же правил — `PayloadErrorsTest` зелёный с T-024, `IgnoredEventsTest` зелёный с T-026 |
| Уведомления (unit) | `tests/Unit/Jobs/*`, `tests/Unit/Mail/*` | контракт задания отправки (tries/backoff/afterCommit, T-012), темы писем, dedupe-ключ |
| Схема и генерация БД | `tests/Feature/{DomainSchemaTest,DomainModelsTest,DomainFactoriesTest,DatabaseReproducibilityTest,DemoOrdersSeederTest}.php` | DDL/индексы/FK, модели, фабрики, полный цикл migrate↔rollback, демо-сидер (T-003–T-008) |
| API заказов | `tests/Feature/Api/*` | маршруты по контракту, список/карточка, 422 параметров (T-011), фильтр email — точное совпадение без учёта регистра, trim-край, комбинация со status + email в links, 422 формата/длины email (позеленел и дополнен в T-036), контрактная валидация по `task/openapi.yaml` и края карточки/списка — S-24 «страница за пределами», nullable-поля, одинаковые SKU/USD, JSON без Accept (T-018), строгие ключи контракта на всех уровнях (T-037), N+1-детекторы константного числа запросов — `OrdersApiEagerLoadingTest` (T-037), средняя страница с фильтром и пустая таблица контрактно, отрицательные/дробные page/per_page и регистр status, полная матрица Accept (T-038) |
| Админка | `tests/Feature/Admin/AdminOrdersPagesTest.php` | Inertia-оболочки, вспомогательные props, `/` → `/admin/orders` (T-013); инварианты гибрида списка: состав props не расширяется при наличии заказов в БД, query-string админ-URL контроллером игнорируется (T-039); зеркальный инвариант карточки — существующий заказ не попадает в props оболочки (T-041, 615 → 616) |
| Вебхук (feature, красные) | `tests/Feature/Webhook/MarketplaceWebhookRouteTest.php` | маршрут, middleware, маппер ответов (T-010) |
| Вебхук (feature) | `tests/Feature/Webhook/MarketplaceWebhook{PayloadErrors,PayloadLimit,IgnoredEvents,DuplicateDelivery,SnapshotApplication,OrderLock}Test.php` | HTTP-сценарии T-017: ошибки подписи (SecurityTest, зелёный с T-024) и payload (PayloadErrorsTest — зелёный с T-024: 400/422 парсера наблюдаемы из HTTP; PayloadLimitTest — зелёный с T-025: 413 до JSON-разбора), 200-исходы `ignored`/`duplicate` (зелёные с T-026), занятый lock заказа (OrderLockTest — зелёный с T-027: 503), применение снимка (`processed`/`stale` — с T-028, `rejected` — с T-029, история — с T-030, outbox-записи — с T-032: SnapshotApplication — 11 зелёных), версии/терминальные статусы (S-01…S-07, S-12…S-14, S-16…S-19, S-27, S-29…S-31, S-36…S-37, S-39); сводная карта — в `DECISIONS.md` (задача 017) |
| Вебхук: outbox и конкуренция (feature) | `tests/Feature/Webhook/MarketplaceWebhook{OutboxTransaction,OutboxCommit,CompetingVersions}Test.php` | сценарии T-019, зелёные с T-032: транзакционный outbox (rollback уносит заказ+outbox+журнал; постановка после processed, письмо не в запросе; 422 без постановки), «нет постановки до commit» (DatabaseMigrations, реальный database queue-драйвер), конкурирующие версии S-20 (tie по кортежу, оба порядка доставки; зелёные с T-028, повторы duplicate по FR-ORD-01 — ожидание повтора проигравшего исправлено основной сессией) и дедупликация shipped по треку S-11 (UNIQUE dedupe_key) |
| Хелперы вебхуков | `tests/Support/*`, `tests/Feature/Webhook/{MarketplaceExamples,SignedWebhook,MutatedWebhookBody}Test.php` | читатель сырых примеров, подпись/отправка запросов, билдеры мутантов (T-015) |
| Админка: браузерные smoke | `e2e/*.spec.ts` | Playwright против поднятого стенда: список/фильтры/пагинация/пустое состояние, карточка/суммы/история/404, query-string после перезагрузки (T-047, FR-ADM-07) — отдельная команда `sail composer e2e`, см. [раздел Playwright](#браузерные-smoke-тесты-админки-playwright-t-047) |
| Консоль/очередь | `tests/Feature/Console/*`, `tests/Feature/Jobs/*` | sweeper, prune, регистрация расписания, контракт и идемпотентность задания отправки (T-012/T-034); интеграционные проверки sweeper↔задание (реальная отправка, контракт `updated_at`↔backoff) и prune↔мягкая ссылка на заказ (T-035) |
| Письма: содержимое (feature) | `tests/Feature/Mail/OrderMailContentTest.php` | тексты писем §6.5, зелёные с T-033 (красные наборы T-019): пример `paid` дословно (построчные строки состава/сумм), суммы+валюта (A-26), `shipped` — служба/трек/ссылка, `cancelled` — причина/«Причина не указана» и строка возврата только при `paid_at ≠ null`, plain-text-only |
| Отправка писем: механика (feature) | `tests/Feature/Jobs/SendOrderNotification{Delivery,Retry}Test.php` | механика отправки, зелёная с T-034 (красные наборы T-019): happy-path `sent` всех трёх типов (адрес/тема/`sent_at`/`attempts`), получатель из текущего состояния, 9 запрещённых ячеек матрицы §6.5 → `skipped(superseded)`+`dedupe_key=NULL`, `invalid_recipient`/`no_shipment`, пара S-38 (dedupe-ключ следует текущему треку / занятый ключ → `skipped(duplicate_tracking)`), retry-контракты FR-MAIL-05/S-21 (временная ошибка — реальный transport с `TransportException`); сюда же влит скелетный набор T-012 (идемпотентность шага 1, постановка) |

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

Красные API-наборы T-018 (историческое состояние этапа 3): фильтр
email (`OrdersListEmailFilterApiTest`, 3 теста; фактический ответ —
500 LogicException-заглушки `OrderListQuery`) — позеленел в T-036,
см. раздел «Обновление карты после T-036».
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

## Матрица сценариев приёмки S-01…S-39 ↔ тесты

> T-045 (DOC-04в/г): соответствие каждого сценария §8 ТЗ фактическому
> тесту сьюта (624 теста, `sail composer tests`). Статусы: ✅ — покрыт
> тестом; ◐ — покрыт частично (какие части и где); ✋ — не покрыт
> автоматическим тестом. Сценарий не помечается выполненным без теста.

| ID | Сценарий (кратко) | Статус | Тест(ы) |
|---|---|---|---|
| S-01 | штатный `created → updated → paid → shipped` (примеры) | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_full_lifecycle_applies_snapshots_in_delivery_order` (статус/позиции/total/история/письма paid+shipped); части — `HistoryBuilderTest`, `NotificationTriggerTest`, `OrderMailContentTest` |
| S-02 | повтор события (новые timestamp/подпись, изменённое тело) | ✅ | `MarketplaceWebhookDuplicateDeliveryTest::test_redelivery_with_new_timestamp_and_altered_payload_is_duplicate` |
| S-03 | повтор после успешной обработки спустя время | ✅ | `MarketplaceWebhookDuplicateDeliveryTest::test_sequential_redelivery_after_processing_is_duplicate` |
| S-04 | N параллельных доставок одного события | ◐ | последовательная часть — S-02/S-03 (одна строка журнала, `deliveries`); истинная параллельность процессов — P1-план, [раздел ниже](#интеграционные-p1-тесты-и-mailpit-план) |
| S-05 | старый `order.updated` после `order.paid` | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_older_update_after_paid_is_stale_and_keeps_paid_state` |
| S-06 | `order.paid` раньше `order.created` | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_paid_before_created_creates_order_then_treats_created_as_stale` |
| S-07 | `order.shipped` раньше `order.paid` | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_shipped_first_restores_missed_paid_fact` |
| S-08 | отмена оплаченного; затем опоздавший `order.paid` | ◐ | письмо `cancelled` с фразой возврата — `OrderMailContentTest::test_cancelled_letter_of_paid_order_includes_reason_and_refund_line`; «письмо `paid` не отправляется» — компонентно (`NotificationPolicyTest` матрица paid@cancelled, `NotificationTriggerTest`, stale-правило S-05); сквозного теста полной последовательности нет |
| S-09 | отмена неоплаченного (`paid_at=null`) | ✅ | `OrderMailContentTest::test_cancelled_letter_of_unpaid_order_has_no_refund_line_and_default_reason` |
| S-10 | `paid` в очереди, до отправки применён `cancelled` | ✅ | `SendOrderNotificationDeliveryTest::test_disallowed_combination_skips_letter_and_releases_dedupe_key` (paid@cancelled → `skipped(superseded)`, ключ освобождён) |
| S-11 | коррекция `shipped → paid`, повторный `shipped` с тем же/другим треком | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_manual_rollback_shipped_to_paid_applies_without_correction_email` + `MarketplaceWebhookCompetingVersionsTest::test_same_tracking_number…`/`test_different_tracking_number…` |
| S-12 | неверная подпись | ✅ | `MarketplaceWebhookSecurityTest::test_forged_signature_is_rejected_without_side_effects` |
| S-13 | тело изменено на байт / пересериализовано | ✅ | `MarketplaceWebhookSecurityTest::test_single_tampered_byte…`/`test_reserialized_body…` |
| S-14 | timestamp за границей ±300 с / ровно на границе | ✅ | `VerifyMarketplaceSignatureTest` (unit: окно и границы), `MarketplaceWebhookSecurityTest::test_stale_timestamp…`, `MarketplaceWebhookRouteTest::test_stale_timestamp_shares_unified_401_body` |
| S-15 | нет заголовков / подпись без `sha256=` / не hex | ✅ | `VerifyMarketplaceSignatureTest::test_missing_required_header…`/`test_malformed_signature_header…` + `SignedWebhookTest::test_request_without_signature_header_is_unauthorized_on_real_route` |
| S-16 | `X-Webhook-Id` ≠ `body.id` | ✅ | `MarketplaceWebhookPayloadErrorsTest::test_webhook_id_mismatch_returns_400_without_side_effects` |
| S-17 | не JSON / нет `data.order` / нет `status` у известного типа | ✅ | `MarketplaceWebhookPayloadErrorsTest` (400 `invalid_json`/не-UTF-8; 422 `invalid_envelope`/`invalid_snapshot`) |
| S-18 | неизвестный `type` без `data.order`; повтор | ✅ | `MarketplaceWebhookIgnoredEventsTest::test_unknown_type…`/`test_unknown_type_redelivery_answers_duplicate` |
| S-19 | смешанные часовые пояса (моменты vs «настенное» время) | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_fresher_wall_time_but_older_moment_is_stale` + `SnapshotVersionComparatorTest`, DTO-тесты |
| S-20 | два снимка с одинаковым `updated_at` | ✅ | `MarketplaceWebhookCompetingVersionsTest::test_tiebreak_winner…`/`test_older_competing_version_arriving_last_is_stale` |
| S-21 | SMTP временно недоступен, затем восстановлен | ✅ | `SendOrderNotificationRetryTest::test_temporary_smtp_failure…`/`test_recovery_after_temporary_failures_still_sends_letter`/`test_attempt_limit…`; зависший `sending` не переотправляется — `SweepPendingNotificationsCommandTest` (sweeper не трогает `sending`) |
| S-22 | эталон `signature-example.md` | ✅ | `SignedWebhookTest::test_signature_matches_reference_example`/`test_signed_reference_request_passes_signature_on_real_route` + `MarketplaceExamplesTest` |
| S-23 | тело с финальным `\n` и без него | ✅ | `SignedWebhookTest::test_post_delivers_body_byte_per_byte`/`test_post_delivers_body_without_trailing_newline` |
| S-24 | `GET /api/orders`: сортировка/фильтры/пагинация/422 | ✅ | `OrdersListApiTest` (сортировка, статус, границы, 422-provider, links) + `OrdersListEmailFilterApiTest` + `OrdersListContractApiTest::test_page_beyond_last…` |
| S-25 | `GET /api/orders/{id}`: контракт, nullable, `subtotal`, `history`, 404 | ✅ | `OrderShowApiTest` + `OrderShowContractApiTest` (схема `task/openapi.yaml`) |
| S-26 | заказ без `phone`, одинаковые SKU, `USD`/`EUR` | ✅ | `OrderShowContractApiTest::test_created_order_has_all_nullable_fields_null…`/`test_same_sku_items_and_foreign_currency_survive_roundtrip` |
| S-27 | расхождение `total` с арифметикой позиций | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_total_arithmetic_mismatch_is_still_processed` + warning в `MarketplaceEventParserTest` |
| S-28 | письма: темы, `To`, UTF-8, содержимое | ◐ | `Mail::fake()`-часть — `OrderMailContentTest` (тексты дословно) + `SendOrderNotificationDeliveryTest` (адресат/тема/`sent_at`); интеграционный тест с Mailpit API (`:8025/api/v1/messages`) — P1-план, [раздел ниже](#интеграционные-p1-тесты-и-mailpit-план) |
| S-29 | `delivered` + свежий снимок с откатом | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_fresh_snapshot_after_delivered_is_rejected` |
| S-30 | `cancelled` + свежий снимок `paid`/`shipped` | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_fresh_snapshot_after_cancelled_is_rejected` |
| S-31 | `delivered` + свежий снимок с тем же статусом | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_same_terminal_status_with_new_data_is_applied` |
| S-32 | админка: список (фильтры, пагинация, query-string, пустые состояния) | ✅ | серверная часть — `AdminOrdersPagesTest` (assertInertia-оболочки, вспомогательные props, инварианты гибрида); браузерная часть — `e2e/admin-orders-list.spec.ts` + `e2e/admin-query-string.spec.ts` (T-047); пустое состояние API — `OrdersListContractApiTest::test_empty_orders_list…` |
| S-33 | админка: карточка (позиции, суммы, история, 404-состояние) | ✅ | серверная часть — `AdminOrdersPagesTest` (оболочка, props без заказа) + 404 API JSON `OrderShowApiTest`; браузерная часть — `e2e/admin-order-show.spec.ts` (T-047): клиентское состояние «не найден», позиции/суммы/история против `GET /api/orders/{id}` |
| S-34 | `.env.example` содержит все переменные | ✅ | `EnvExampleSyncTest` (FR-ENV-05) + CI-джоба `env-sync` |
| S-35 | команды README на чистом клоне | ✋ | автоматического теста нет (по природе сценария); чистый запуск проверен вручную в T-042 (временный клон `bin/setup` + холодный старт основного дерева — `DECISIONS.md`, задача 042), финальная приёмка — T-046 |
| S-36 | строковый статус `refunded`/неизвестный | ✅ | `MarketplaceWebhookIgnoredEventsTest::test_unknown_status_is_ignored_and_keeps_existing_order` |
| S-37 | свежая коррекция `shipped → paid` без письма-исправления | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_manual_rollback_shipped_to_paid_applies_without_correction_email` + `NotificationTriggerTest` |
| S-38 | смена tracking number до отправки `shipped`-письма | ✅ | `SendOrderNotificationDeliveryTest::test_shipped_dedupe_key_follows_current_tracking_number`/`test_shipped_with_existing_key_for_current_tracking_is_skipped_as_duplicate` |
| S-39 | переход между терминальными статусами (`delivered ↔ cancelled`) | ✅ | `MarketplaceWebhookSnapshotApplicationTest::test_terminal_to_terminal_transition_is_rejected` + юниты `ProcessMarketplaceEventTest` |

Итог: 35 из 39 сценариев покрыты тестами напрямую (✅); 3 — частично
(◐: S-04/S-28 — P1-интеграционные план-наборы, S-08 — компонентное
покрытие без сквозного теста последовательности); S-35 — ручной
сценарий приёмки (T-042/T-046). Сценарии S-32/S-33 закрыты полностью
с появлением браузерных smoke-тестов T-047 (серверная часть —
PHPUnit, браузерная — Playwright). Частично покрытые сценарии
не считаются закрытыми до появления недостающих тестов (T-046).

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

### Обновление карты после T-033 (тексты писем)

Реализованы тексты трёх писем §6.5 — blade-вьюхи
`resources/views/mail/order-{paid,shipped,cancelled}.blade.php`:
приветствие с «!» (фикс расхождения каркаса T-012 с примером ТЗ,
DECISIONS 012/019), строки позиций/сумм и код валюты — дословно
§6.5/A-26 (стоимость позиции — `Money::subtotal`, единая точка
арифметики T-022), shipped — служба/трек/ссылка, cancelled — причина
или «Причина не указана» (Q26) и строка возврата только при
`paid_at ≠ null` (срок — `notifications.refund_days`). Канонический
прогон (Paratest): **586 тестов = 563 зелёных + 23 красных
(5 failures + 18 errors)** (было 586 = 558 + 28, из них 10 failures)
— ожидание задачи сошлось точно. Изменения против T-032:

| Набор | Изменение |
|---|---|
| `Feature/Mail/OrderMailContentTest` | все 5 failures позеленели без правки тестов: дословные строки примера §6.5 (включая кавычки в названии товара — текст без HTML-entity), суммы+валюта USD, shipped-блок (служба/трек/ссылка), причина/возврат/«Причина не указана», plain-text-only |

Оставшиеся красные по владельцам: Jobs 20 (T-034 — заглушки шагов 2–5
`SendOrderNotification`: 18 errors + 2 failures Retry), email-фильтр 3
(T-036). Ни один зелёный не упал; новых красных вне карты нет.

### Обновление карты после T-034 (механика отправки уведомлений)

Реализованы шаги 2–5 §7 в `SendOrderNotification`: Redis-lock
`order:{id}` (не взят — warning + release по backoff, запись не
меняется), предотправочная транзакция с `FOR UPDATE` строки заказа и
проверками по ТЕКУЩЕМУ состоянию (матрица → `skipped(superseded)`,
email → `invalid_recipient`, shipped без shipment → `no_shipment`,
сверка dedupe-ключа с текущим треком → атомарный update либо
`skipped(duplicate_tracking)`; все skip с `dedupe_key=NULL`),
атомарный захват `pending → sending` (0 строк — тихий выход),
синхронная отправка (`Mail::to(...)->send(...)`, мейблы без
`ShouldQueue`) с фиксацией `sent`/`pending`+`attempts++`+backoff/
`failed`+`last_error`; терминальная `failed()`-ветвь перезагружает
запись по `notificationId` (переход только `sending → failed` —
карта T-012). Канонический прогон (Paratest):
**590 тестов = 587 зелёных + 3 красных (3 failures)**, 2269 assertion
(было 586 = 563 + 23, из них 5 failures) — владельцы и состав красноты
сошлись с ожиданием точно; +4 новых зелёных по ревью T-034 (прецедент
T-029) — итог 590 вместо ожидавшихся 586. Изменения против T-033:

| Набор | Изменение |
|---|---|
| `Feature/Jobs/SendOrderNotificationDeliveryTest` | все 17 владельцев позеленели без правки тестов; сюда же влит скелетный набор T-012 `SendOrderNotificationSkeletonTest` (4 теста: идемпотентность шага 1 — отсутствующая/не-pending запись, постановка; тест заглушки перевёрнут по правилу задачи 016 — pending-запись теперь пиннит реальную отправку `sent`); +1 новый по ревью (P2-2): исключение из `$lock->release()` в finally не маскирует результат отправки (мок Lock, warning `lock_release_failed`); набор 22 зелёных |
| `Feature/Jobs/SendOrderNotificationRetryTest` | все 3 зелёные без правки: временная ошибка SMTP (реальный transport с `TransportException`) — `pending`+`attempts++`; 5-я попытка — `failed`+`last_error`; восстановление — `sent` с суммарными попытками; +3 новых по ревью (P2-2): failed() переводит `sending` → `failed` (новый инстанс, перезагрузка по id), не трогает `pending`/терминальный `sent`; занятый lock (`order_lock_timeout=0`, паттерн OrderLockTest) — письмо не уходит, запись не меняется, warning `order_busy_retry_scheduled`; набор 6 зелёных |

Оставшиеся красные по владельцам: email-фильтр 3 (T-036, failures —
заглушка `OrderListQuery`). Ни один зелёный не упал; новых красных
вне карты нет. Warning-логи NFR-06 реализованы (токены
`notification_skipped`/`notification_retry_scheduled`/
`notification_failed`/`notification_job_failed`/
`order_busy_retry_scheduled`/`lock_release_failed`); первые ассерты
логов добавлены по ревью T-034 (занятый lock, отказ release),
полное покрытие токенов — остаток для будущих задач.

### Обновление карты после T-035 (outbox sweeper и очистка событий)

Команды `notifications:sweep`/`webhooks:prune`, расписание и пороги
реализованы честно ещё в T-012 (позже усилены аудитами P2.1/P3.8),
поэтому красных владельцев T-035 в сьюте не было. Дельта задачи —
покрытие, невозможное при скелетном задании T-012 (sweeper-тесты
фиксировали только постановку под `Queue::fake`), и наблюдаемость
команд (остаток T-012): info-токены `notifications_swept`/
`webhook_events_pruned` для непустых прогонов (NFR-06). Канонический
прогон (Paratest): **593 теста = 590 зелёных + 3 красных (3 failures)**
(было 590 = 587 + 3) — состав красноты не изменился (email-фильтр
T-036), новых красных нет. Изменения:

| Набор | Изменение |
|---|---|
| `Feature/Console/SweepPendingNotificationsCommandTest` | +2: e2e-критерий FR-MAIL-06 — переставленное sweeper'ом задание исполняется (sync-драйвер тестовой очереди, без `Queue::fake`) и доезжает до письма: запись `sent`, `attempts=1`, адресат — текущий email заказа, info-токен; контракт `updated_at` (T-034) — вернувшаяся в `pending` после неудачной попытки запись (`attempts=1`, метка свежее порога) sweeper'ом не переставляется, ждёт собственного backoff-повтора |
| `Feature/Console/PruneWebhookEventsCommandTest` | +1: удаление устаревшего события с мягкой ссылкой `order_id` не трогает заказ и его outbox-записи (связь без FK — решение T-003); info-токен непустого прогона |

### Обновление карты после T-036 (query-класс и API список заказов)

Реализованы фильтр email и усиление его валидации: `ListOrdersRequest`
проверяет формат адреса + `max:255` (аудит T-018: было только
`nullable|string`) и нормализует значение `mb_strtolower(trim())`
до `OrderListParameters`; `OrderListQuery` сравнивает точным
совпадением по `customer_email_normalized` (формула A-30 — та же,
какой поле записывается при применении снимка; покрывает индекс
`(customer_email_normalized, placed_at)`), заглушка `LogicException`
удалена. Канонический прогон (Paratest):
**595 тестов = 595 зелёных + 0 красных** (было 593 = 590 + 3) —
**первый полностью зелёный прогон сьюта**. Изменения:

| Набор | Изменение |
|---|---|
| `Api/OrdersListEmailFilterApiTest` | все 3 владельца позеленели: точное совпадение без учёта регистра (S-24), точность (префикс/расширение домена/несуществующий → пустая страница), комбинация со status + email в links. Попутно исправлена латентная опечатка в первом тесте (T-018): запрос `ANNA.SMRNOVA@…` без буквы «i» не мог совпасть с сидом `Anna.Smirnova@…` ни при какой корректной реализации точного совпадения — до T-036 недостижима (тест падал на 500 раньше сравнения), фикс по прецеденту механических правок T-028; интент «запрос в верхнем регистре находит смешанный регистр хранения» сохранён. +1 новый: trim-край входа фильтра (пробельные края не мешают точному совпадению, A-30) |
| `Api/OrdersListApiTest` | заглушечный тест `test_email_filter_fails_closed_until_t_036` (ждал 500 от fail-closed заглушки) удалён по остатку ревью T-018 — иначе позеленение фильтра сломало бы его; вместо него покрытие усиленной валидации: +2 случая в `invalidParametersProvider` (email не по формату; формат-валидный адрес длиннее 255 — падает именно `max:255`), +пин точного сообщения формата email в `test_invalid_parameters_yield_user_visible_messages` |

Счёт: 593 − 1 заглушечный + 1 trim + 2 провайдера = 595; ни один
зелёный не упал, новых красных нет.

### Обновление карты после T-037 (ресурс списка и карточка заказа)

Задача-аудит: ресурсы `OrderListItemResource`/`OrderResource` реализованы
честно ещё в T-011 (subtotal унифицирован с `Money::subtotal` в T-022),
красных владельцев не было — код приложения не менялся, дельта задачи —
покрытие строгости, недостижимое прежними наборами. Канонический прогон
(Paratest): **604 теста = 604 зелёных + 0 красных** (было 595 = 595 + 0).
Изменения:

| Набор | Изменение |
|---|---|
| `Api/OrdersListContractApiTest` | +1: строгие ключи элемента списка — ровно поля схемы OrderListItem и `customer{email,name}` без phone. JSON Schema контракта additionalProperties не запрещает (описание openapi.yaml разрешает дополнительные поля), поэтому контрактная валидация лишних полей не ловит — строгость «только поля контракта» (чек-лист T-037) пиннится сравнением множества ключей (`assertEqualsCanonicalizing` на `array_keys`) |
| `Api/OrderShowContractApiTest` | +1: строгие ключи карточки на всех уровнях — 16 полей схемы Order, `customer{email,name,phone}`, каждый item{sku,name,quantity,price,subtotal}, shipment-объект из трёх полей (shipped-заказ — ветка, противоположная null-краю T-018), каждый факт history{status,at} |
| `Api/OrdersApiEagerLoadingTest` | новый набор (T-037): точечные N+1-детекторы — число SQL-запросов карточки не зависит от объёма items/history (2 позиции/2 факта против 5/3), списка — от числа заказов (2 против 5); пиннится константа, а не абсолютное число (состав запросов байндинга/пагинатора — внутренности фреймворка). Ленивой загрузке связей и так мешает глобальный `Model::shouldBeStrict` — тест ловит не-lazy паттерны |
| `Unit/Support/ApiDateTest` | новый набор (T-037): края формата дат, недостижимые через эндпоинты (MySQL DATETIME хранит UTC без смещения и дробей) — null-проход для nullable-полей, отбрасывание микросекунд, приведение ненулевого смещения (±) к тому же моменту UTC |

Подтверждено без новых тестов (зелёные T-011): даты UTC `+00:00` без
долей и `created_at`/`updated_at` = даты MarketHub (`placed_at`/
`source_updated_at` — пины точных строк и регэксп ISO_UTC в
`OrderShowApiTest`), 404 JSON независимо от Accept (2 теста там же).

### Обновление карты после T-038 (контрактные тесты API — финальный аудит)

Задача-аудит: контрактные наборы существуют с T-018 (валидатор
`opis/json-schema` — DECISIONS 018), позеленели к T-036 и усилены
T-037; код приложения не менялся — дельта задачи — закрытие последних
пробелов покрытия (аудит-таблица и выводы — `DECISIONS.md`, задача
038). Канонический прогон (Paratest): **613 тестов = 613 зелёных +
0 красных** (было 604 = 604 + 0). Изменения:

| Набор | Изменение |
|---|---|
| `Api/OrdersListApiTest` | +5 случаев в `invalidParametersProvider`: page = −1 и дробный 1.5, per_page = −1 и дробный 99.5 (дробные — числовые, но не integer: правило integer; −1 — integer, валится min:1), status = PAID — регистр: enum контракта openapi.yaml нижнего регистра, совпадение чувствительно к регистру |
| `Api/OrdersListContractApiTest` | +2: средняя страница с фильтром (`?status=paid&per_page=1&page=2`) — строковая ветка next до T-038 не валидировалась ни разу (страница 1 и S-24 «за пределами» дают next=null; prev на S-24 уже строка), средняя страница закрывает одновременные строковые prev+next; заодно первый схемный прогон отфильтрованного ответа (links несут фильтры, withQueryString); пустая таблица — total=0, from/to/prev/next=null, обёртка контрактна (край «пустого состояния» админки S-32) |
| `Api/OrderShowContractApiTest` | +1: 200 карточки с `Accept: text/html` — JSON (последняя незакрытая ячейка матрицы Accept) |
| `Api/OrderShowApiTest` | +1: 404 без заголовка Accept вовсе — JSON с message (до T-038 404 проверялся только с application/json через getJson и text/html) |

Подтверждено без новых тестов (зелёные T-011/T-036/T-037): остальная
матрица Accept (список-200 html/без заголовка, список-422 html,
карточка-404 html, карточка-200 без заголовка); негативные email
(мусор/длина) и остальные значения параметров provider'а; границы
per_page 1/100 позитивно; S-24 «страница за пределами» и строгие
ключи. Контракт `task/openapi.yaml` не менялся; расхождений
реализации с контрактом не найдено.

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

## Браузерные smoke-тесты админки (Playwright, T-047)

> FR-ADM-07: обязательные браузерные smoke-тесты админки — список,
> фильтры, пагинация, карточка, 404-состояние, сохранение
> query-string. Закрывают браузерную часть сценариев приёмки S-32/S-33.

Smoke-набор гоняется **против поднятого стенда** и поэтому не входит
ни в PHPUnit-сьют, ни в `sail composer max`/`check` (они обязаны быть
зелёными без браузера и без стенда — CI и верификация влития).
Отдельная команда локального набора проверок: `sail composer e2e`
(обёртка над `npm run e2e`); финальная приёмка T-046 прогоняет её
обязательно. В GitHub Actions набор не включён — нужен полный стек
с БД, демо-сидами и сборкой фронта в одной джобе; решение и остаток —
`DECISIONS.md`, задача 047.

### Состав (`e2e/*.spec.ts`, 11 сценариев)

| Спека | Проверяет |
|---|---|
| `admin-orders-list.spec.ts` | `/` → `/admin/orders`; данные списка — из клиентского запроса `GET /api/orders` (сверка с телом ответа: строки, сводка пагинации); фильтр по статусу (все строки «Отменён», `?status=…`); фильтр по email (точное совпадение без учёта регистра, демо-пул T-007); пагинация «Вперёд» → `?page=2` по `meta`; пустое состояние «Ничего не найдено» + сброс |
| `admin-order-show.spec.ts` | карточка «богатого» заказа: шапка/покупатель, позиции, разбивка сумм (позиции/доставка/скидка/итого), отправка, история-таймлайн — всё сверяется с `GET /api/orders/{id}`; несуществующий id → 404 от API → «Заказ не найден» и возврат к списку |
| `admin-query-string.spec.ts` | email- и статус-фильтры, применённые через UI, восстанавливаются после `page.reload()` (URL, поля, данные); копируемая ссылка `?status=…&per_page=…&page=2` открывает то же состояние и переживает перезагрузку |

### Окружение и установка (один раз на машину)

- Тесты и браузер работают **внутри Sail-контейнера** `laravel.test`:
  из контейнера приложение доступно на `http://localhost` (тот же
  `artisan serve` на :80). Vite dev-сервер не нужен — проверяется
  сборка `public/build` (стенд поднимает её стартовым процессом T-042).
- Браузер — chromium headless в `var/playwright`
  (`PLAYWRIGHT_BROWSERS_PATH`; переменная задана в npm-скриптах
  `e2e`/`e2e:install` — `playwright-core` читает её при своём импорте,
  раньше выполнения тела `playwright.config.ts`). Каталог смонтирован
  в контейнер вместе с проектом и переживает пересоздание контейнера;
  в Git не попадает (`.gitignore`, `/var`).
- Установка браузера: `sail npm run e2e:install`. Системные библиотеки
  chromium ставятся от root (у sail-юзера нет sudo), разово и после
  пересоздания контейнера:

  ```bash
  docker compose exec -u root laravel.test bash -lc \
    'cd /var/www/html && npx playwright install-deps chromium'
  ```

- Запуск: `sail composer e2e` (или `sail npm run e2e`); точечно —
  `sail npm run e2e -- admin-order-show`. Артефакты упавших прогонов
  (скриншот, trace, snapshot DOM) — `var/playwright-artifacts/`.
  С хоста вместо контейнера — `PLAYWRIGHT_BASE_URL=http://localhost`
  и браузеры хоста (не основной путь; версии гарантирует контейнер).

### Данные и предсказуемость

Набор работает на живом стенде с демо-данными сидера T-007 и **не
готовит и не мутирует данные** (никаких `migrate:fresh` — стенд общий).
Предсказуемость — из двух источников:

1. гарантии демо-сида: пул фиксированных email (`boris@shop.test`,
   `Anna@Shop.Test`, … — каждый встречается в наборе), все пять
   статусов паттерном, ≥ 21 заказа → ≥ 2 страницы по 20; нарушения
   дают понятные guard-сообщения ассертов;
2. самосогласованность UI ↔ API: спеки сами читают `GET /api/orders`
   (тем же методом контракта, что и админка) и сверяют отрисованные
   строки/суммы/историю с телом ответа — сценарии устойчивы к любым
   дополнительным заказам на стенде (например, от ручной отправки
   событий через `send-event.php`).

Суммы и подписи статусов в спеках считаются зеркалами клиента
(`Intl.NumberFormat('ru-RU', currency)` из `lib/format.ts`, целые
копейки из `lib/money.ts`, карта подписей `StatusBadge.vue`) —
`e2e/helpers/admin.ts`. Форматтеры и helpers PHPUnit не используются:
набор живёт отдельно от `tests/` и не участвует в `composer tests`.

## Интеграционные P1-тесты и Mailpit (план)

> Статус: план (T-019). Механика отправки реализована (T-034) —
> процессные сценарии очереди/писем исполнимы на стенде; фиксация
> наборов и финальная приёмка — **T-046**. Требование §11 ТЗ:
> конкурентность — P1, интеграционный тест с Mailpit — P1
> (опционально). Наборы ниже **не входят** в phpunit-сьют
> (`sail composer tests`): зависят от поднятого стенда и живого
> воркера; `markTestSkipped` не используется (правило завершённых
> задач) — запуск отдельной командой.

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
  см. README, раздел «Отправка тестовых событий»);
- дождаться обработки воркером (poll `order_notifications.status`
  до `sent`);
- прочитать письмо через Mailpit API `GET :8025/api/v1/messages`:
  получатель = `customer.email`, тема дословно из §6.5 (символ «№»
  U+2116), тело — plain text UTF-8 с составом/суммами;
- сценарий NFR-05: остановить Mailpit/воркер → временная ошибка →
  `pending` с `attempts++` (задание само повторяется по backoff
  10 с/1 мин/5 мин/15 мин) → восстановление → `sent` (S-21 в бою);
- зависший `sending` (убить воркер в окне отправки) — sweeper
  не переотправляет, запись видна ручной процедуре из
  `docs/operations.md`.

Команды запуска (стенд — как в README; фиксация наборов — T-046):

```bash
sail up -d   # стенд: приложение, MySQL, Redis, Mailpit; воркер — сервис
             # laravel.horizon, планировщик — сервис scheduler (T-042)
MARKETPLACE_WEBHOOK_SECRET=whsec_test_5f1d8c2a9b \
  php task/examples/send-event.php http://localhost/api/webhooks/marketplace \
  task/examples/order-paid.json   # подписанное событие (секрет — как в .env)
curl -s http://localhost:8025/api/v1/messages | jq '.messages[].Subject'
```

### 3. Ручной путь через Mailpit (доступен уже сейчас)

1. `sail artisan migrate:fresh --seed` — демо-данные (T-007);
2. отправить тестовое событие — см. README, раздел «Отправка тестовых
   событий» (с T-026 неизвестный тип/статус отвечает 200 `ignored`,
   повтор — 200 `duplicate`; с T-028 известное событие с валидным
   снимком применяется — 200 `processed`, устаревшая версия —
   200 `stale`);
3. открыть Mailpit UI `http://localhost:8025` — при поднятом
   воркере/Horizon письмо появится после применения события
   (outbox → очередь → SMTP Mailpit; конвейер полностью реализован
   с T-034 — раздел 2 выше).

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
