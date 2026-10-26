<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Version;

/**
 * Сравнение версий снимков заказа (FR-ORD-02/FR-ORD-03, скелет T-009).
 *
 * Входящий снимок применяется только если его версия строго больше
 * сохранённой; иначе HTTP-ответ `stale` без изменения заказа (A-14).
 * Сравнение — по кортежу `(order.updated_at, event.created_at, event.id)`
 * как по моментам времени (нормализация в UTC), строковое сравнение
 * ISO-дат запрещено. Реализация — задача T-023; тесты — T-016.
 */
final class SnapshotVersionComparator
{
    /**
     * Строго ли версия входящего снимка больше сохранённой версии заказа.
     *
     * @throws \LogicException пока не реализовано (задача T-023)
     */
    public function isNewer(Version $incoming, Version $stored): bool
    {
        throw new \LogicException('Сравнение версий снимков ещё не реализовано (задача T-023).');
    }
}
