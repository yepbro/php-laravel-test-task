<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Достоверный факт истории статусов заказа (FR-HIS-02, T-009).
 *
 * «Статус X в момент Y», следующий из снимка: `created @ created_at`,
 * `paid @ paid_at` (если не null), `cancelled @ cancelled_at` (если не
 * null), `shipped`/`delivered` @ `updated_at` снимка; `at` — момент
 * по данным маркетплейса, а не время получения вебхука (FR-HIS-01).
 *
 * Вывод фактов из снимка — HistoryBuilder (T-030); `source_event_id`
 * проставляет потребитель при записи в `order_status_history`, факту
 * он не принадлежит.
 */
final readonly class HistoryFact
{
    public function __construct(
        public OrderStatus $status,
        public CarbonImmutable $at,
    ) {}
}
