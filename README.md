# Тестовое задание: PHP / Laravel

Задание — в [`task/README.md`](task/README.md).

Проект запускается через [Laravel Sail](https://laravel.com/docs/sail) (Docker): PHP 8.5, MySQL 8.4, Redis 7, Mailpit и воркер очереди [Horizon](https://laravel.com/docs/horizon).

## Требования

- Docker + Docker Compose
- PHP 8.5 и Composer — опционально: ускоряют установку зависимостей;
  без них `bin/setup` ставит их в контейнере `laravelsail/php84-composer`

## Первый запуск

Одна команда из чистого клона:

```bash
git clone <url репозитория> && cd php-laravel-test-task
./bin/setup
```

`bin/setup` создаст `.env` (приведя `WWWUSER`/`WWWGROUP` к фактическому
uid/gid хоста), установит зависимости и поднимет стек. Дальше стартовый
процесс контейнера `laravel.test` автоматически и идемпотентно
(`bin/start-container`, §5.2 ТЗ):

1. дождётся MySQL и применит миграции (`migrate --force`);
2. сгенерирует `APP_KEY`, если он пуст;
3. соберёт фронтенд (`npm ci` + `npm run build`), если нет
   `public/build/manifest.json` — первая сборка занимает несколько минут
   (ход процесса: `./vendor/bin/sail logs -f laravel.test`).

Очередь (`laravel.horizon`) и планировщик (`scheduler`) стартуют только
после готовности приложения — healthcheck `GET /up` (порядок старта —
в [`docs/operations.md`](docs/operations.md)).

Обновление кода (`git pull`) с изменениями фронтенда: пересоберите
ассеты вручную — `./vendor/bin/sail composer build` (bootstrap собирает
фронт только при отсутствии манифеста, существующий манифест он не
инвалидирует).

То же вручную:

```bash
composer install          # или docker run — команда в bin/setup
cp .env.example .env      # при uid/gid хоста ≠ 1000 поправьте WWWUSER/WWWGROUP
./vendor/bin/sail up -d --build   # ключ, миграции и сборка выполнятся автоматически
```

После этого приложение доступно на **http://localhost**.

Админка заказов (Inertia) — **http://localhost/admin/orders**: `/` перенаправляет
туда автоматически. Данные заказов страница запрашивает у API
`/api/orders` (гибрид Q3, см. `docs/architecture.md`); состояние фильтров
и страницы живёт в query-string адреса (`status`, `email`, `page`,
`per_page`) — ссылки вида `/admin/orders?status=paid&page=2` можно
копировать и открывать напрямую; для наполнения демо-заказами запустите
сидер (раздел ниже). Сборка фронта — `./vendor/bin/sail composer build`
(при запуске через `bin/setup` выполняется автоматически).

Для dev-режима фронтенда (hot reload) вместо `npm run build` запустите `./vendor/bin/sail npm run dev` — Vite поднимется на http://localhost:5173.

## Демо-данные (сидеры)

Для локальной разработки и ручной проверки фильтров, сортировки и пагинации:

```bash
./vendor/bin/sail artisan migrate:fresh --seed   # чистая база + демо-набор одной командой
./vendor/bin/sail artisan db:seed                # пересоздать демо-набор на текущей базе
```

Состав набора фиксирован (строятся на фабриках, см. `database/seeders/DemoOrdersSeeder.php`;
перечисленное ниже — при объёме по умолчанию 30):

- заказы во всех пяти статусах, включая оплаченную отмену (`paid()->cancelled()`), с историей статусов;
- 0/1/несколько позиций, заказы без телефона, с отправлением и без, с причиной отмены и без;
- повторяющиеся email в разном регистре — проверка точного фильтра email без учёта регистра;
- уникальные `placed_at` — проверка сортировки `placed_at DESC`;
- немного событий вебхуков и записи уведомлений во всех статусах outbox.

Объём задаётся переменной `SEED_DEMO_ORDERS` (по умолчанию 30; минимум 5 — покрытие всех
статусов). Так как artisan выполняется внутри контейнера, переменную указывают в `.env`,
а не префиксом к команде:

```bash
# в .env: SEED_DEMO_ORDERS=200 — затем
./vendor/bin/sail artisan db:seed   # большой набор для проверки пагинации
```

Демо-сидер **полностью пересоздаёт** доменные таблицы (`orders`, `order_items`,
`order_status_history`, `webhook_events`, `order_notifications`) — запускайте только
в локальной/тестовой базе. В production (`APP_ENV=production`) он отключён: `DatabaseSeeder`
его пропускает, а прямой запуск `./vendor/bin/sail artisan db:seed --class=DemoOrdersSeeder`
завершается ошибкой. Тесты проекта от сидеров не зависят и используют фабрики напрямую.

## Вебхуки MarketHub и письма

### Секрет подписи `MARKETPLACE_WEBHOOK_SECRET`

Секрет вебхуков задаётся в `.env` (в `.env.example` — демонстрационное
значение из материалов задания):

```dotenv
MARKETPLACE_WEBHOOK_SECRET=whsec_test_5f1d8c2a9b
```

- **пустое значение — fail closed**: все вебхуки отклоняются с 401
  (секрет читается только через `config('marketplace.webhook_secret')`,
  в коде `env()` не используется);
- для проверки со своим секретом задайте его в `.env` (после правки
  перезапуск не обязателен для веб-сервера; воркер Horizon перечитывает
  конфиг после `./vendor/bin/sail restart`) и передавайте тем же
  значением в переменной окружения команды `send-event.php`;
- сгенерировать свой: `openssl rand -hex 16`;
- секрет намеренно **не пробасывается через compose** из окружения
  оболочки — только `.env` (обоснование и защита tripwire-тестом —
  [`docs/operations.md`](docs/operations.md), «Безопасность»).

### Отправка тестовых событий

Подписать и отправить событие можно готовым скриптом из материалов задания
(подписывает байты файла «как есть»; нужен PHP с curl на хосте). Все пять
примеров — в порядке жизненного цикла заказов; на чистой базе каждый
отвечает `200 processed`:

```bash
for f in order-created order-updated order-paid order-shipped order-cancelled; do
  MARKETPLACE_WEBHOOK_SECRET=whsec_test_5f1d8c2a9b php task/examples/send-event.php \
      http://localhost/api/webhooks/marketplace "task/examples/$f.json"
done
```

Один пример вручную (секрет должен совпадать с `.env`):

```bash
MARKETPLACE_WEBHOOK_SECRET=whsec_test_5f1d8c2a9b php task/examples/send-event.php \
    http://localhost/api/webhooks/marketplace task/examples/order-paid.json
```

Повторная отправка того же файла — тот же `event.id` — отвечает
`200 duplicate` (доставки считаются в `webhook_events.deliveries`,
состояние заказа и письма не дублируются). Отправка устаревшего примера
после более свежего (например, `order-updated` после `order-shipped`)
отвечает `200 stale` — версия снимка не новее сохранённой. Цикл
`created → updated → paid → shipped` оставляет заказ `ord_7Q2KX9M4`
в статусе `shipped` с полной историей; `order-cancelled.json` — отдельный
оплаченный заказ `ord_3HV8PW2C`. Полный контракт (заголовки, подпись
через `openssl`, коды ответов `processed/duplicate/stale/ignored/rejected`,
401/400/413/422/503) — в [`docs/webhooks.md`](docs/webhooks.md).

### Письма покупателю

Письма («оплачен» / «передан в доставку» / «отменён») отправляются
**асинхронно** через очередь: вебхук → `order_notifications` (outbox) →
Horizon → SMTP Mailpit. При поднятом стеке письмо появляется
в Mailpit в течение нескольких секунд после события:

- веб-интерфейс — **http://localhost:8025**;
- API — `curl -s http://localhost:8025/api/v1/messages | jq '.messages[].Subject'`.

Проверить состояние отправок можно и в БД (таблица `order_notifications`);
аварийные процедуры (зависшие `sending`, повтор `failed`) — в
[`docs/operations.md`](docs/operations.md).

## Сервисы и порты

| Сервис                  | Адрес                                                | Переменная порта в `.env`        |
| ----------------------- | ---------------------------------------------------- | -------------------------------- |
| Приложение              | http://localhost                                     | `APP_PORT` (по умолчанию 80)     |
| Vite dev-сервер         | http://localhost:5173                                | `VITE_PORT`                      |
| MySQL                   | 127.0.0.1:3306 (`sail` / `password`, база `markethub`) | `FORWARD_DB_PORT`                |
| Redis                   | 127.0.0.1:6379                                       | `FORWARD_REDIS_PORT`             |
| Mailpit (веб-интерфейс) | http://localhost:8025                                | `FORWARD_MAILPIT_DASHBOARD_PORT` |
| Mailpit (SMTP)          | 127.0.0.1:1025                                       | `FORWARD_MAILPIT_PORT`           |
| Horizon (дашборд)       | http://localhost/horizon                             | — (маршрут приложения)           |
| Очередь (Horizon)       | —                                                    | — (сервис `laravel.horizon`)     |
| Планировщик             | —                                                    | — (сервис `scheduler`)           |

Очередь работает на Redis (`QUEUE_CONNECTION=redis`), задачи обрабатывает сервис
`laravel.horizon` (`php artisan horizon`), расписание — сервис `scheduler`
(`php artisan schedule:work`); оба стартуют вместе со стеком автоматически
после готовности приложения и отслеживаются `./vendor/bin/sail ps`
(Horizon — healthcheck'ом). Стеком управляет только `sail` (не «сырой»
`docker compose`): он экспортирует `WWWUSER`/`WWWGROUP` для compose.

Если порт занят на хосте, переопределите его в `.env` — контейнеры пересоздаются `./vendor/bin/sail up -d`.

## HTTP API и админка

Публичные эндпоинты — только контракт `task/openapi.yaml` плюс health
(проверяется тестом `OrdersApiRouteTableTest`); авторизация вне объёма
задания (§4 ТЗ). Все ответы `api/*` — JSON независимо от `Accept`.

| Метод | Путь | Назначение |
| --- | --- | --- |
| `POST` | `/api/webhooks/marketplace` | приём вебхука MarketHub — контракт: [`docs/webhooks.md`](docs/webhooks.md) |
| `GET` | `/api/orders` | список заказов: `status`, `email` (точное совпадение без регистра), `page`, `per_page` (1…100, по умолчанию 20) |
| `GET` | `/api/orders/{id}` | карточка заказа: позиции с `subtotal`, `history`, nullable-поля всегда присутствуют |
| `GET` | `/up` | health-check приложения |
| `GET` | `/admin/orders`, `/admin/orders/{id}` | админка (Inertia-оболочки; данные — клиентскими запросами к API) |
| `GET` | `/horizon` | дашборд очереди (состояние, упавшие задания) |

Быстрая проверка API:

```bash
curl -s 'http://localhost/api/orders?status=paid&per_page=5' | jq '.meta'
curl -s http://localhost/api/orders/ord_7Q2KX9M4 | jq '.data.history'
```

(второй пример — id заказа из `task/examples/order-*.json` после отправки
событий или сида). Ответы API валидируются контрактными тестами по
`task/openapi.yaml` (см. [`docs/testing.md`](docs/testing.md)).

## Тесты и линт

> **Все команды запускаются внутри контейнеров через Sail** — это гарантирует те же
> версии PHP/Node и доступ к MySQL/Redis, что и в окружении CI:
>
> ```bash
> ./vendor/bin/sail composer min
> ```
>
> Далее `sail` — сокращение для `./vendor/bin/sail` (алиас в конце README).

### Какие проверки есть

**Бэкенд (PHP):**

| Проверка           | Что делает                                                       | Конфигурация   |
| ------------------ | ---------------------------------------------------------------- | -------------- |
| Pint               | код-стайл с автофиксом (preset `per`, `declare(strict_types=1)`) | `pint.json`    |
| PHPStan + Larastan | статический анализ, level 10                                     | `phpstan.neon` |

**Фронтенд (Vue/TS/CSS):**

| Проверка  | Что делает                                                                                                      | Конфигурация                     |
| --------- | --------------------------------------------------------------------------------------------------------------- | -------------------------------- |
| Prettier  | форматирование с автофиксом (+ сортировка import'ов и Tailwind-классов)                                         | `.prettierrc`, `.prettierignore` |
| vue-tsc   | проверка типов TypeScript                                                                                       | `tsconfig.json`                  |
| Stylelint | CSS: без `!important`, цвета только через `var(...)`-токены (сырые значения — только в `resources/css/app.css`) | `stylelint.config.mjs`           |
| hex-линт  | запрет hex/rgb/hsl-литералов в Tailwind-классах в Vue/TS — только дизайн-токены                                 | `scripts/lint-tailwind-hex.mjs`  |
| ESLint    | линт Vue/TS с автофиксом                                                                                        | `eslint.config.js`               |

**Тесты (PHPUnit, БД `testing` создаётся автоматически при старте контейнера MySQL):**

| Команда               | Что делает                         |
| --------------------- | ---------------------------------- |
| `sail composer tests` | тесты через Paratest (параллельно) |
| `sail composer test`  | тесты последовательно              |

**Браузерные smoke-тесты админки (Playwright, T-047):** `sail composer e2e` —
список/фильтры/пагинация, карточка с 404-состоянием, сохранение query-string —
против поднятого стенда с демо-данными. Требует установленного браузера
(один раз: `sail npm run e2e:install` и системные библиотеки chromium от root —
точные команды и детали в [`docs/testing.md`](docs/testing.md), раздел Playwright).
В `sail composer max` и CI не входит: гоняется отдельной командой против живого
приложения (финальная приёмка — T-046).

Синхронность переменных окружения (`.env.example` ⇄ `env()` в `config/*.php`,
`${…}` в `compose.yaml`, `VITE_*` фронтенда, `.env.testing`) автоматически
проверяется тестом `EnvExampleSyncTest` — новая переменная добавляется
в `.env.example` в том же коммите, что и код (FR-ENV-04), иначе прогон красный.

### Как пользоваться

Типовые сценарии:

```bash
sail composer check    # единая проверка качества: Pint --test → PHPStan → тесты → vue-tsc
sail composer min     # быстрая проверка: back + front (стиль, типы, линт — с автофиксом)
sail composer max     # полный прогон: min + тесты (Paratest)
sail composer back    # только бэкенд: Pint + PHPStan
sail composer front   # только фронтенд: Prettier + vue-tsc + Stylelint + hex-линт + ESLint
sail composer build   # сборка фронтенда (vite build, манифест в public/build)
sail composer e2e     # браузерные smoke-тесты админки (Playwright, нужен стенд + браузер)
```

Точечные команды:

| Команда                   | Что делает                                      |
| ------------------------- | ----------------------------------------------- |
| `sail composer check`     | Единая проверка: Pint `--test` → PHPStan → тесты → vue-tsc |
| `sail composer pint`      | Только Pint (исправление стиля)                 |
| `sail composer pint-show` | Только Pint в режиме проверки (без изменений)   |
| `sail composer phpstan`   | Только PHPStan                                  |
| `sail composer tests`     | Только тесты (`artisan test -p`)                |
| `sail composer test`      | Тесты без параллелизма (`artisan test`)         |
| `sail composer build`     | Сборка фронтенда (`vite build`)                 |
| `sail composer e2e`       | Браузерные smoke-тесты админки (Playwright, против стенда) |

Шаги фронта по отдельности (`sail npm run …`):

```bash
sail npm run format               # Prettier — исправить
sail npm run format:check         # Prettier — только проверить
sail npm run lint                 # ESLint — исправить
sail npm run lint:check           # ESLint — только проверить
sail npm run lint:css             # Stylelint
sail npm run lint:tailwind-hex    # hex-литералы в Tailwind-классах
sail npm run lint:tailwind-hex:report  # … с детализацией по файлам
sail npm run types:check          # vue-tsc
sail npm run e2e                  # Playwright smoke-тесты админки (против стенда)
sail npm run e2e:install          # установка chromium в var/playwright (один раз)
```

> «Только проверить, ничего не менять» — это `pint-show`, `phpstan`, `format:check`,
> `lint:check`, `lint:css`, `lint:tailwind-hex`, `types:check`.
> Команды `pint`, `prettier` и `eslint` (без `-show`/`:check`) **изменяют файлы**.

## CI (GitHub Actions)

Пуш в `main` и pull request запускают воркфлоу
[`.github/workflows/ci.yml`](.github/workflows/ci.yml) — те же инструменты
с теми же конфигами, что и локальные `sail composer check` + `sail composer
front` (check-режим) + `sail composer build`, тремя параллельными джобами:

| Джоба      | Что выполняет                                                                                 |
| ---------- | -------------------------------------------------------------------------------------------- |
| `backend`  | Pint (`--test`) → Larastan level 10 → PHPUnit (Paratest) против MySQL 8.4 (service-контейнер) |
| `frontend` | Prettier → vue-tsc → Stylelint → hex-линт → ESLint → сборка фронтенда (`vite build`)          |
| `env-sync` | `EnvExampleSyncTest` — синхронность переменных `.env.example` с кодом (без БД)                |

Redis в CI не нужен: тестовое окружение (`phpunit.xml`) подменяет
кэш/сессии/почту/очередь на `array`/`sync` (блокировки — `ArrayStore`).
Детали окружения джоб и локальный эквивалент каждой — в
[`docs/testing.md`](docs/testing.md).

## Документация

- [`docs/architecture.md`](docs/architecture.md) — схема данных, доменный
  слой, скелеты вебхука/API/уведомлений/админки;
- [`docs/webhooks.md`](docs/webhooks.md) — контракт вебхука: заголовки,
  подпись, коды ответов, ручная отправка;
- [`docs/testing.md`](docs/testing.md) — как запускать тесты, окружение,
  структура наборов, матрица сценариев приёмки S-01…S-39;
- [`docs/operations.md`](docs/operations.md) — порты, очередь Horizon,
  планировщик, retention, безопасность;
- [`docs/http/requests.http`](docs/http/requests.http) — готовые HTTP-примеры
  к API (REST Client / HTTP Client);
- [`DECISIONS.md`](DECISIONS.md) — принятые решения и допущения (сводные
  разделы + журнал по задачам).

## Управление

```bash
./vendor/bin/sail up -d      # запустить
./vendor/bin/sail down       # остановить (данные в volume сохраняются)
./vendor/bin/sail ps         # статус контейнеров
./vendor/bin/sail logs -f    # логи
```

Сброс окружения (локальная разработка):

```bash
./vendor/bin/sail down -v                     # остановить и удалить volume с данными MySQL/Redis
./vendor/bin/sail artisan migrate:fresh --seed  # пересоздать схему и залить демо-данные
```

После `sail down -v` следующий `sail up -d` поднимает базу с нуля:
миграции применяет стартовый процесс контейнера автоматически.

> Совет: добавьте алиас `alias sail='./vendor/bin/sail'` и используйте короткие команды `sail up -d`, `sail artisan ...`, `sail npm ...`.
