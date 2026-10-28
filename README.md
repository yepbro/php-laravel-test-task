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
сидер (раздел ниже).

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

## Тестовое событие вебхука

Подписать и отправить событие можно готовым скриптом из материалов задания
(подписывает байты файла «как есть»; нужен PHP с curl на хосте):

```bash
MARKETPLACE_WEBHOOK_SECRET=whsec_test_5f1d8c2a9b php task/examples/send-event.php \
    http://localhost/api/webhooks/marketplace task/examples/order-paid.json
```

> Подпись сверяется (T-024): невалидное тело отвечает 400/422 сразу.
> Журнал событий работает (T-026): неизвестный тип/статус отвечает
> 200 `ignored`, повторная доставка того же `event.id` — 200
> `duplicate`. Применение снимка работает (T-028): известное событие
> с валидным снимком отвечает 200 `processed` (устаревшая версия —
> 200 `stale`). Письма уходят покупателю через очередь (outbox —
> T-032, отправка — T-034): при поднятом Horizon письмо события видно
> в Mailpit. Контракт вебхука и коды ответов —
> [`docs/webhooks.md`](docs/webhooks.md).

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

### Как пользоваться

Типовые сценарии:

```bash
sail composer check    # единая проверка качества: Pint --test → PHPStan → тесты → vue-tsc
sail composer min     # быстрая проверка: back + front (стиль, типы, линт — с автофиксом)
sail composer max     # полный прогон: min + тесты (Paratest)
sail composer back    # только бэкенд: Pint + PHPStan
sail composer front   # только фронтенд: Prettier + vue-tsc + Stylelint + hex-линт + ESLint
sail composer build   # сборка фронтенда (vite build, манифест в public/build)
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
| `sail composer ci:check`  | Проверка как в CI: `vp check` + vue-tsc + тесты |

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
```

> «Только проверить, ничего не менять» — это `pint-show`, `phpstan`, `format:check`,
> `lint:check`, `lint:css`, `lint:tailwind-hex`, `types:check`.
> Команды `pint`, `prettier` и `eslint` (без `-show`/`:check`) **изменяют файлы**.

## Документация

- [`docs/architecture.md`](docs/architecture.md) — схема данных, доменный
  слой, скелеты вебхука/API/уведомлений/админки;
- [`docs/webhooks.md`](docs/webhooks.md) — контракт вебхука: заголовки,
  подпись, коды ответов, ручная отправка;
- [`docs/testing.md`](docs/testing.md) — как запускать тесты, окружение,
  структура наборов;
- [`docs/operations.md`](docs/operations.md) — порты, очередь Horizon,
  планировщик, retention, безопасность;
- [`DECISIONS.md`](DECISIONS.md) — журнал принятых решений и допущений.

## Управление

```bash
./vendor/bin/sail up -d      # запустить
./vendor/bin/sail down       # остановить
./vendor/bin/sail ps         # статус контейнеров
./vendor/bin/sail logs -f    # логи
```

> Совет: добавьте алиас `alias sail='./vendor/bin/sail'` и используйте короткие команды `sail up -d`, `sail artisan ...`, `sail npm ...`.
