# Операционное руководство

> Дата актуализации: 2026-10-27. Каркас (T-014): стек, порты, очереди,
> команды обслуживания и правила безопасности. Разделы про аварийные
> процедуры писем (зависшие `sending`, повторная отправка) и
> автоматизированный прогон планировщика дополняются задачами
> T-034/T-035/T-042 (DOC-02). Единый источник переменных —
> [`.env.example`](../.env.example) (DOC-03).

## Содержание

- [Управление стеком](#управление-стеком)
- [Сервисы и порты](#сервисы-и-порты)
- [Очередь (Horizon)](#очередь-horizon)
- [Планировщик и команды обслуживания](#планировщик-и-команды-обслуживания)
- [Переменные окружения](#переменные-окружения)
- [Безопасность](#безопасность)
- [Аварийные процедуры](#аварийные-процедуры)

## Управление стеком

Окружение — [Laravel Sail](https://laravel.com/docs/sail) (Docker):
PHP 8.5, MySQL 8.4, Redis 7, Mailpit, воркер очереди Horizon.

```bash
./vendor/bin/sail up -d      # запустить (первый запуск собирает образ)
./vendor/bin/sail down       # остановить
./vendor/bin/sail ps         # статус контейнеров и healthcheck
./vendor/bin/sail logs -f    # логи всех сервисов
```

Первичный запуск с нуля (клон → зависимости → окружение → стек) —
в [`README.md`](../README.md), раздел «Первый запуск».

## Сервисы и порты

| Сервис | Адрес | Переменная порта в `.env` |
|---|---|---|
| Приложение | http://localhost | `APP_PORT` (по умолчанию 80) |
| Админка заказов | http://localhost/admin/orders | — |
| Дашборд Horizon | http://localhost/horizon | — |
| Vite dev-сервер | http://localhost:5173 (только `npm run dev`) | `VITE_PORT` |
| MySQL | 127.0.0.1:3306 (`sail` / `password`, база из `DB_DATABASE`) | `FORWARD_DB_PORT` |
| Redis | 127.0.0.1:6379 | `FORWARD_REDIS_PORT` |
| Mailpit (веб-интерфейс) | http://localhost:8025 | `FORWARD_MAILPIT_DASHBOARD_PORT` |
| Mailpit (SMTP) | 127.0.0.1:1025 | `FORWARD_MAILPIT_PORT` |

Занятый порт на хосте переопределяется в `.env` с последующим
`./vendor/bin/sail up -d` (контейнеры пересоздаются). Локальное `.env`
может расходиться с примером в портах (например, `FORWARD_REDIS_PORT=6380`) —
это нормально.

## Очередь (Horizon)

- Задачи очереди — Redis (`QUEUE_CONNECTION=redis`); обрабатывает
  отдельный сервис `laravel.horizon` (`php artisan horizon`), стартует
  вместе со стеком, отслеживается healthcheck'ом (`horizon:status`).
- Дашборд Horizon — `/horizon`: состояние супервизоров, недавние
  и упавшие задания (`/horizon/failed`).
- Уведомления покупателю уходят через job `SendOrderNotification`
  (скелет T-012; параметры попыток — `NOTIFICATIONS_TRIES`,
  backoff `10 с / 1 мин / 5 мин / 15 мин` — `config/marketplace.php`).

## Планировщик и команды обслуживания

Задачи регистрируются в `routes/console.php`:

| Команда | Расписание | Назначение |
|---|---|---|
| `notifications:sweep` | каждую минуту | повторная постановка в очередь `pending`-уведомлений старше `NOTIFICATIONS_SWEEP_AFTER_SECONDS` (FR-MAIL-06) |
| `webhooks:prune` | раз в сутки | удаление сырых событий `webhook_events` старше `MARKETPLACE_EVENTS_RETENTION_DAYS` (90 дней), батчами по 1000 (Q20) |

Обе команды защищены `withoutOverlapping` и запускаются вручную:

```bash
./vendor/bin/sail artisan notifications:sweep
./vendor/bin/sail artisan webhooks:prune
```

> **Статус автоматизации.** Отдельный сервис `scheduler`
> (`php artisan schedule:work`) в compose ещё не добавлен — это задача
> T-042. До него расписание выполняется только при ручном запуске
> `schedule:work`/команд (см. выше); регистрация задач проверяется
> тестом `ScheduleRegistrationTest`.

Retention событий управляется `MARKETPLACE_EVENTS_RETENTION_DAYS`
(дефолт 90); уведомления не удаляются автоматически — это записи
outbox-аудита.

## Переменные окружения

Полный список с комментариями — [`.env.example`](../.env.example);
секция вебхуков описана в [`webhooks.md`](webhooks.md).

Методика сверки синхронности (ручная; CI-автоматизация — T-043):

```bash
# env() в конфигах (активные драйверы проекта)
grep -rhoP "env\(\s*'([A-Z0-9_]+)'" config/ | grep -oP "'[A-Z0-9_]+'" | tr -d "'" | sort -u
# переменные compose.yaml (${PWD} — builtin compose, не из .env)
grep -oP '\$\{[A-Z0-9_]+' compose.yaml | tr -d '${' | sort -u
# фронтенд
grep -rn "import.meta.env.VITE_" resources/ vite.config.ts
```

Правило включения переменной (зафиксировано в `DECISIONS.md`, задача
014): переменные активных механизмов, определяющие выбор драйвера
и обязательные параметры подключения, + всё, что читают `compose.yaml`
и Vite + доменные (`MARKETPLACE_*`, `NOTIFICATIONS_*`, `SEED_*`);
тонкая настройка активных секций (`REDIS_PREFIX`, `SESSION_SAME_SITE`,
`HORIZON_*`, …) и переменные неактивных драйверов скелета Laravel
(SQS, Beanstalkd, AWS/S3, memcached и т.п.) в `.env.example` не входят.

## Безопасность

- **Fail closed секрет вебхуков** (Q11): пустой
  `MARKETPLACE_WEBHOOK_SECRET` → все вебхуки отклоняются (401) — в
  том числе при `APP_ENV=production` секрет обязан быть задан;
  демо-значение из `.env.example` (`whsec_test_5f1d8c2a9b`) — только
  для локальной разработки.
- Секрет, подпись и сырое тело вебхука не пишутся в логи и ответы;
  email маскируется (NFR-04/06).
- `APP_DEBUG=false` по умолчанию (`.env.example`) — стектрейсы наружу
  не отдаются (NFR-04).
- Авторизация API и админки отсутствует по границам проекта (§4);
  сервис не публикуется в интернет без дополнительной защиты.

## Аварийные процедуры

Каркас; наполняется по мере реализации отправки писем:

- **Упавшие задания очереди** — дашборд `/horizon/failed`;
  повторная постановка — из дашборда (`Retry`) либо
  `queue:retry {id}`; после `NOTIFICATIONS_TRIES` неудачных попыток
  уведомление получает статус `failed` (реализация — T-034).
- **Зависшие `sending`-уведомления** (воркер упал во время отправки)
  не переотправляются автоматически — осознанная политика «не
  дублировать» (Q25/NFR-05); ручная процедура диагностики будет
  описана в T-034/T-035.
- **Потерянная постановка в очередь** (Redis недоступен в момент
  `dispatch`) — восстанавливается sweeper'ом `notifications:sweep`
  (FR-MAIL-06).
