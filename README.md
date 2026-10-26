# Тестовое задание: PHP / Laravel

Задание — в [`task/README.md`](task/README.md).

Проект запускается через [Laravel Sail](https://laravel.com/docs/sail) (Docker): PHP 8.5, MySQL 8.4, Redis 7, Mailpit и воркер очереди [Horizon](https://laravel.com/docs/horizon).

## Требования

- Docker + Docker Compose
- PHP 8.5 и Composer — только для первичной установки зависимостей (`vendor/` нужен и для `sail`, и для сборки образа)

## Первый запуск

```bash
git clone <url репозитория> && cd php-laravel-test-task

# 1. Зависимости
composer install

# 2. Окружение
cp .env.example .env

# 3. Поднять стек (при первом запуске собирается образ, это займёт несколько минут)
./vendor/bin/sail up -d --build

# 4. Ключ приложения и миграции
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate

# 5. Фронтенд
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

После этого приложение доступно на **http://localhost**.

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

## Сервисы и порты

| Сервис                  | Адрес                                                | Переменная порта в `.env`        |
| ----------------------- | ---------------------------------------------------- | -------------------------------- |
| Приложение              | http://localhost                                     | `APP_PORT` (по умолчанию 80)     |
| Vite dev-сервер         | http://localhost:5173                                | `VITE_PORT`                      |
| MySQL                   | 127.0.0.1:3306 (`sail` / `password`, база `laravel`) | `FORWARD_DB_PORT`                |
| Redis                   | 127.0.0.1:6379                                       | `FORWARD_REDIS_PORT`             |
| Mailpit (веб-интерфейс) | http://localhost:8025                                | `FORWARD_MAILPIT_DASHBOARD_PORT` |
| Mailpit (SMTP)          | 127.0.0.1:1025                                       | `FORWARD_MAILPIT_PORT`           |
| Horizon (дашборд)       | http://localhost/horizon                             | — (маршрут приложения)           |

Очередь работает на Redis (`QUEUE_CONNECTION=redis`), задачи обрабатывает отдельный сервис `laravel.horizon` —
он стартует вместе со стеком автоматически (`sail up -d`) и отслеживается healthcheck'ом (`sail ps`).

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
sail composer min     # быстрая проверка: back + front (стиль, типы, линт — с автофиксом)
sail composer max     # полный прогон: min + тесты (Paratest)
sail composer back    # только бэкенд: Pint + PHPStan
sail composer front   # только фронтенд: Prettier + vue-tsc + Stylelint + hex-линт + ESLint
```

Точечные команды:

| Команда                   | Что делает                                      |
| ------------------------- | ----------------------------------------------- |
| `sail composer pint`      | Только Pint (исправление стиля)                 |
| `sail composer pint-show` | Только Pint в режиме проверки (без изменений)   |
| `sail composer phpstan`   | Только PHPStan                                  |
| `sail composer tests`     | Только тесты (`artisan test -p`)                |
| `sail composer test`      | Тесты без параллелизма (`artisan test`)         |
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

## Управление

```bash
./vendor/bin/sail up -d      # запустить
./vendor/bin/sail down       # остановить
./vendor/bin/sail ps         # статус контейнеров
./vendor/bin/sail logs -f    # логи
```

> Совет: добавьте алиас `alias sail='./vendor/bin/sail'` и используйте короткие команды `sail up -d`, `sail artisan ...`, `sail npm ...`.
