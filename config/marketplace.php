<?php

declare(strict_types=1);

use App\Enums\OrderStatus;

return [

    /*
    |--------------------------------------------------------------------------
    | Интеграция с маркетплейсом MarketHub (T-009)
    |--------------------------------------------------------------------------
    |
    | Параметры приёма вебхуков MarketHub: секрет подписи, окно timestamp,
    | блокировка заказа, терминальные статусы, срок хранения сырых событий
    | и параметры уведомлений покупателю. Значения по умолчанию — из §5.3 ТЗ
    | (plan/TZ-markethub-orders-service-v1.5.md); имена переменных окружения
    | читаются только здесь (правило: env() вне конфигов запрещён).
    |
    */

    // Секрет подписи вебхуков. Пустое значение по умолчанию — fail closed:
    // без заданного секрета все вебхуки отклоняются (Q11/FR-WH-04).
    'webhook_secret' => env('MARKETPLACE_WEBHOOK_SECRET', ''),

    // Допустимое отклонение X-Webhook-Timestamp от текущего времени,
    // секунды, в обе стороны (FR-WH-03).
    'webhook_tolerance' => (int) env('MARKETPLACE_WEBHOOK_TOLERANCE', 300),

    // Лимит тела вебхука, байты (FR-WH-12): строго больше — 413.
    // Фиксированное протокольное значение ТЗ (1 MiB), переменной окружения
    // не имеет — как backoff уведомлений. Проверяет middleware
    // LimitMarketplaceWebhookBody по Content-Length и фактическому
    // размеру сырых байтов (Sail — php artisan serve, nginx нет).
    'webhook_max_body_bytes' => 1024 * 1024,

    // Ожидание общей Redis-блокировки заказа order:{id}, секунды (FR-ORD-07).
    'order_lock_timeout' => (int) env('MARKETPLACE_ORDER_LOCK_TIMEOUT', 3),

    // Срок владения блокировкой заказа, секунды; должен превышать
    // SMTP timeout (MAIL_TIMEOUT), чтобы lock не истёк во время отправки
    // письма (FR-ORD-07, FR-MAIL-03).
    'order_lock_lease' => (int) env('MARKETPLACE_ORDER_LOCK_LEASE', 15),

    // Терминальные статусы заказа (Q35/FR-ORD-09): из delivered и cancelled
    // откаты запрещены, включая переходы между самими терминальными
    // статусами; обновление данных при том же статусе разрешено.
    // Единственное место истины: OrderStatus::isTerminal() читает этот
    // список, а не дублирует набор case'ов.
    'terminal_statuses' => [
        OrderStatus::DELIVERED->value,
        OrderStatus::CANCELLED->value,
    ],

    // Срок хранения сырых тел событий в webhook_events, дни (Q20).
    'events_retention_days' => (int) env('MARKETPLACE_EVENTS_RETENTION_DAYS', 90),

    'notifications' => [

        // Максимум попыток отправки одного письма; после исчерпания —
        // failed (FR-MAIL-05).
        'max_attempts' => (int) env('NOTIFICATIONS_TRIES', 5),

        // Паузы между повторными попытками отправки, секунды:
        // 10 с, 1 мин, 5 мин, 15 мин (FR-MAIL-05). Фиксированные значения
        // из ТЗ, переменной окружения не имеют.
        'backoff' => [10, 60, 300, 900],

        // Порог «догоночной» постановки pending-уведомлений планировщиком:
        // старше этого возраста письма переставляются в очередь повторно
        // (FR-MAIL-06; Redis мог быть недоступен в момент dispatch).
        'sweep_after_seconds' => (int) env('NOTIFICATIONS_SWEEP_AFTER_SECONDS', 120),

        // Технические параметры прогона sweeper'а (без env — настроечные,
        // не бизнес-правила): размер батча выборки и потолок перестановок
        // за один запуск. После долгой недоступности Redis backlog большой,
        // а команда ходит раз в минуту — потолок ограничивает длину
        // прогона, остаток подбирают следующие запуски.
        'sweep_batch_size' => 500,
        'sweep_max_per_run' => 1000,

        // Срок возврата средств в тексте письма об отмене оплаченного
        // заказа, дней (§6.5 ТЗ).
        'refund_days' => (int) env('NOTIFICATIONS_REFUND_DAYS', 10),
    ],
];
