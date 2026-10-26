# Тестовое задание: PHP / Laravel

Задание — в [`task/README.md`](task/README.md).

Проект запускается через [Laravel Sail](https://laravel.com/docs/sail) (Docker): PHP 8.5, MySQL 8.4, Redis, Mailpit.

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

## Сервисы и порты

| Сервис | Адрес | Переменная порта в `.env` |
|---|---|---|
| Приложение | http://localhost | `APP_PORT` (по умолчанию 80) |
| Vite dev-сервер | http://localhost:5173 | `VITE_PORT` |
| MySQL | 127.0.0.1:3306 (`sail` / `password`, база `laravel`) | `FORWARD_DB_PORT` |
| Redis | 127.0.0.1:6379 | `FORWARD_REDIS_PORT` |
| Mailpit (веб-интерфейс) | http://localhost:8025 | `FORWARD_MAILPIT_DASHBOARD_PORT` |
| Mailpit (SMTP) | 127.0.0.1:1025 | `FORWARD_MAILPIT_PORT` |

Если порт занят на хосте, переопределите его в `.env` — контейнеры пересоздаются `./vendor/bin/sail up -d`.

## Тесты и линт

```bash
# PHPUnit (БД testing создаётся автоматически при старте контейнера MySQL)
./vendor/bin/sail test

# Полная проверка: Pint + PHPStan + PHPUnit
composer test
```

## Управление

```bash
./vendor/bin/sail up -d      # запустить
./vendor/bin/sail down       # остановить
./vendor/bin/sail ps         # статус контейнеров
./vendor/bin/sail logs -f    # логи
```

> Совет: добавьте алиас `alias sail='./vendor/bin/sail'` и используйте короткие команды `sail up -d`, `sail artisan ...`, `sail npm ...`.
