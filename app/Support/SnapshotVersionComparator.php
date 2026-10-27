<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Version;

/**
 * Сравнение версий снимков заказа (FR-ORD-02/FR-ORD-03, T-023).
 *
 * Входящий снимок применяется только если его версия строго больше
 * сохранённой; иначе HTTP-ответ `stale` без изменения заказа (A-14).
 *
 * Версия — кортеж `(order.updated_at, event.created_at, event.id)`
 * (FR-ORD-02): компоненты сравниваются по порядку с ранним выходом —
 * первый неравный компонент решает, равенство передаёт решение
 * следующему; полностью равный кортеж — не новее (строгое `>`:
 * равные и старые версии не перезаписывают заказ).
 *
 * Даты — моменты времени, не строки (FR-ORD-03, S-19): сравнение
 * DateTimeImmutable идёт по мгновениям (включая микросекунды), поэтому
 * `+03:00`, `+00:00` и `Z` с одним мгновением равны. Явная
 * нормализация `->utc()` результата не меняет — она фиксирует
 * правило FR-ORD-03 в коде (в БД версии хранятся как DATETIME в UTC).
 *
 * `event.id` — детерминированный tie-break (Q14/T8: `updated_at`
 * MarketHub точен до секунды) строковым порядком: ULID как источник
 * времени не используется (§3.1 ТЗ). Числовая интерпретация исключена
 * на уровне семантики сравнения: strcmp байтовый безусловно — 'evt_9'
 * лексикографически больше 'evt_10' ('9' > '1'), и даже гипотетические
 * полностью числовые ID не сравнятся как числа (spaceship между
 * такими строками коэрцирует их в числа — потому и не он).
 */
final class SnapshotVersionComparator
{
    /**
     * Строго ли версия входящего снимка больше сохранённой версии заказа.
     *
     * false для равной и старой версии: повторная доставка того же
     * снимка и устаревший снимок не применяются (200 `stale`,
     * FR-ORD-02). Подключение к шагу 13c §7 — задача T-028.
     */
    public function isNewer(Version $incoming, Version $stored): bool
    {
        $updatedComparison = $incoming->orderUpdatedAt->utc() <=> $stored->orderUpdatedAt->utc();

        if ($updatedComparison !== 0) {
            return $updatedComparison === 1;
        }

        $eventComparison = $incoming->eventCreatedAt->utc() <=> $stored->eventCreatedAt->utc();

        if ($eventComparison !== 0) {
            return $eventComparison === 1;
        }

        return strcmp($incoming->eventId, $stored->eventId) > 0;
    }
}
