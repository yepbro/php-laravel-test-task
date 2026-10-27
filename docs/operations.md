# Операционное руководство

> Дата актуализации: 2026-10-28. Каркас (T-014); аварийные процедуры
> писем (зависшие `sending`, повторная отправка, мониторинг) — реализация
> T-034. Автоматизированный прогон планировщика — T-035/T-042 (DOC-02).
> Единый источник переменных — [`.env.example`](../.env.example) (DOC-03).

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
- Уведомления покупателю отправляет задание `SendOrderNotification`
  (реализация — T-034, §7 ТЗ): под блокировкой заказа `order:{id}`
  (той же, что обработчик вебхука) повторно проверяет допустимость
  письма по текущему состоянию, атомарно захватывает запись
  `pending → sending` и отправляет письмо синхронно, фиксируя итог
  в `order_notifications` (`sent` / `skipped` с причиной / `failed`).
- **Повторные попытки** (FR-MAIL-05): максимум
  `NOTIFICATIONS_TRIES` (5) попыток отправки одного письма, паузы
  `10 с / 1 мин / 5 мин / 15 мин` (`config/marketplace.php`,
  `notifications.backoff`). Временная ошибка SMTP возвращает запись
  в `pending` с `attempts++` и повтором по backoff; исчерпание лимита —
  `failed` с последней ошибкой в `last_error`. Транспортные ошибки
  гасятся заданием и **не попадают** в `failed_jobs` — состояние писем
  наблюдается в `order_notifications` (ниже, «Мониторинг»).
- **Взаимодействие backoff ↔ sweeper**: порог sweeper'а
  `NOTIFICATIONS_SWEEP_AFTER_SECONDS` (120 с) меньше старших интервалов
  backoff (5/15 мин) — письмо, ждущее длинной паузы, с T+120 с
  переставляется sweeper'ом раньше собственного повтора очереди:
  эффективный каденс при дефолтах ≈ 10 с / 1 мин / 2 мин / 2 мин
  (дубли постановки безопасны — шаг 1 задания и атомарный захват).
  Если нужен строгий график FR-MAIL-05 — ставьте порог sweeper'а
  больше максимального backoff (например, 1000 с).
- `failed_jobs` наполняется только прочими сбоями задания (БД, блокировки
  на протяжении всех попыток очереди): терминальную ветвь `failed()`
  помечает запись `failed` только из статуса `sending`; запись в
  `pending` остаётся sweeper'у (`notifications:sweep`, FR-MAIL-06).

## Планировщик и команды обслуживания

Задачи регистрируются в `routes/console.php`:

| Команда | Расписание | Назначение |
|---|---|---|
| `notifications:sweep` | каждую минуту | повторная постановка в очередь `pending`-уведомлений старше `NOTIFICATIONS_SWEEP_AFTER_SECONDS` (FR-MAIL-06); батчами `chunkById` с потолком `sweep_max_per_run` (1000) за прогон — после долгого простоя backlog разбирают последовательные запуски |
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

### Мониторинг отправки писем (NFR-06)

Каждое событие конвейера уведомлений пишет warning в лог (канал
`stderr`, JSON) с машинным токеном причины в `reason`:

| Токен | Смысл | Действие оператора |
|---|---|---|
| `notification_skipped` | письмо пропущено: `superseded` (статус ушёл дальше матрицы §6.5), `invalid_recipient` (невалидный email), `no_shipment` (shipped без shipment), `duplicate_tracking` (ключ трека занят) | обычно ничего (штатные ветки); массовый `invalid_recipient` — сигнал о данных маркетплейса |
| `notification_retry_scheduled` | временная ошибка SMTP, повтор по backoff | проверить доступность SMTP (Mailpit в dev) |
| `notification_failed` | попытки письма исчерпаны (5), запись `failed` | разобрать `last_error` записи; см. «Повторная отправка» |
| `notification_job_failed` | задание исчерпало попытки очереди (нетранспортный сбой) | смотреть `/horizon/failed` и `queue:retry` |
| `order_busy_retry_scheduled` | блокировка заказа занята дольше 3 с, задание повторено | разовый — ничего; серия — проверить длинные транзакции по заказу |
| `lock_release_failed` | не удалось освободить lock (авария Redis); владение истечёт по lease (15 с) | разовый — ничего; серия — здоровье Redis |

Сводное состояние писем — таблица `order_notifications`:

```bash
./vendor/bin/sail artisan tinker --execute="print_r(App\\Models\\OrderNotification::query()->selectRaw('status, count(*) as n, max(attempts) as attempts')->groupBy('status')->get()->all());"
```

### Зависшие `sending`-уведомления (воркер упал во время отправки)

Если процесс умер между захватом записи (`pending → sending`) и
фиксацией результата, запись **остаётся в `sending` навсегда**:
автоматически она не переотправляется — осознанная политика
«не дублировать» (Q25/NFR-05: SMTP не имеет общей транзакции с БД,
строгое exactly-once недостижимо; цена политики — риск пропуска
в критическом окне). Sweeper такие записи не ищет, `failed()` не
срабатывает (задание не завершилось исключением).

Ручная процедура:

1. **Найти кандидатов** (зависшими считаются записи, застрявшие
   дольше нескольких минут):

   ```bash
   ./vendor/bin/sail artisan tinker --execute="print_r(App\\Models\\OrderNotification::query()->where('status','sending')->where('updated_at','<',now()->subMinutes(15))->get(['id','order_id','type','attempts','updated_at'])->all());"
   ```

2. **Убедиться, что задание мертво**: нет активной попытки в Horizon
   (дашборд `/horizon`, раздел pending/running) и записи в
   `/horizon/failed` по этому `notification_id`; lease блокировки
   заказа истекает сам через 15 с (`order_lock_lease`).

3. **Проверить, ушло ли письмо фактически** — окно «SMTP принял, статус
   не записан» возможно: Mailpit UI `http://localhost:8025` (поиск
   по адресу получателя/теме письма) или API
   `GET :8025/api/v1/messages`.

4. **Решить по факту** (обе команды — через `sail artisan tinker`;
   меняем только «свою» запись по id):

   - письмо **не ушло** и отправка нужна — вернуть в `pending` и
     переставить задание (проще всего дождаться sweeper: он подберёт
     `pending` старше `NOTIFICATIONS_SWEEP_AFTER_SECONDS` (2 мин);
     немедленно — `App\Jobs\SendOrderNotification::dispatch($id)`):

     ```bash
     ./vendor/bin/sail artisan tinker --execute="App\\Models\\OrderNotification::query()->whereKey($ID)->where('status','sending')->update(['status'=>'pending','updated_at'=>now()]);"
     ```

   - письмо **ушло** (дублировать нельзя) — зафиксировать факт вручную,
     исключив повторную отправку: `status='sent'` (+ `sent_at=now()`,
     `attempts=attempts+1`) либо, если письмо не требовалось, —
     `status='skipped'`, `skip_reason` произвольный для журнала,
     `dedupe_key=NULL` (для shipped — освободить ключ трека).

5. При массовых зависаниях (падение воркера) — искать причину в логах
   Horizon/контейнера `laravel.horizon` (`sail logs laravel.horizon`).

### Повторная отправка `failed`-письма

Запись `failed` терминальна: ни очередь, ни sweeper её не трогают.
Повтор возможен только вручную (после устранения причины — обычно
недоступность SMTP):

```bash
./vendor/bin/sail artisan tinker --execute="$n = App\\Models\\OrderNotification::query()->whereKey($ID)->firstOrFail(); $n->forceFill(['status'=>'pending','attempts'=>0,'last_error'=>null,'updated_at'=>now()])->save(); App\\Jobs\\SendOrderNotification::dispatch($n->id);"
```

### Прочие процедуры

- **Упавшие задания очереди** — дашборд `/horizon/failed`;
  повторная постановка — из дашборда (`Retry`) либо
  `queue:retry {id}`.
- **Потерянная постановка в очередь** (Redis недоступен в момент
  `dispatch`) — восстанавливается sweeper'ом `notifications:sweep`
  (FR-MAIL-06): pending-записи старше `NOTIFICATIONS_SWEEP_AFTER_SECONDS`
  переставляются каждую минуту; дубликаты постановки безопасны — задание
  выходит на не-pending статусе и атомарном захвате (FR-MAIL-04).
- **События `rejected`** (терминальные статусы Q35) — это исходы
  вебхуков, не писем: разбираются по `webhook_events.reject_reason`
  (`docs/webhooks.md`, раздел «Применение снимка»).
