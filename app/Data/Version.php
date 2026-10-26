<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Версия заказа — кортеж сравнимости снимков (FR-ORD-02, T-009).
 *
 * `(order.updated_at (UTC), event.created_at (UTC), event.id)`: входящий
 * снимок применяется только при строго большей версии; `event.id` —
 * детерминированный tie-break, потому что `updated_at` MarketHub даёт
 * с точностью до секунды (T8). ULID в `event.id` как источник времени
 * не используется (§3.1 ТЗ).
 *
 * Компоненты-даты — моменты времени; само сравнение (как моментов,
 * с нормализацией в UTC) — SnapshotVersionComparator (T-023),
 * строкое сравнение ISO-дат запрещено (FR-ORD-03).
 */
final readonly class Version
{
    public function __construct(
        public CarbonImmutable $orderUpdatedAt,
        public CarbonImmutable $eventCreatedAt,
        public string $eventId,
    ) {}
}
