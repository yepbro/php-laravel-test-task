<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\HistoryFact;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;

/**
 * Построение фактов истории статусов из снимка (FR-HIS-01…FR-HIS-04,
 * скелет T-009).
 *
 * История — «статусы, в которых побывал заказ, с моментами по данным
 * маркетплейса». Из каждого валидного снимка (включая устаревший,
 * FR-HIS-02) выводятся следующие из него достоверные факты; при откате
 * статуса применённым снимком добавляется факт ручного исправления
 * (FR-HIS-03). Вставка в БД идемпотентна по UNIQUE(order_id, status, at)
 * — выполняет потребитель (T-030); класс оперирует только чистыми
 * данными, без Eloquent.
 *
 * Реализация — задача T-030; тесты — T-016.
 */
final class HistoryBuilder
{
    /**
     * Факты, достоверно следующие из снимка (FR-HIS-02).
     *
     * @return list<HistoryFact>
     *
     * @throws \LogicException пока не реализовано (задача T-030)
     */
    public function factsFromSnapshot(OrderSnapshot $snapshot): array
    {
        throw new \LogicException('Построение фактов истории ещё не реализовано (задача T-030).');
    }

    /**
     * Факт ручного исправления статуса (FR-HIS-03): применённый снимок
     * откатывает статус — `новый статус @ updated_at снимка`.
     *
     * null, если переход не является откатом (ранг не убывает) — факт
     * не нужен. Переходы из терминальных статусов сюда не доходят:
     * они запрещены раньше (FR-ORD-09).
     *
     * @throws \LogicException пока не реализовано (задача T-030)
     */
    public function rollbackFact(OrderStatus $previousStatus, OrderSnapshot $incoming): ?HistoryFact
    {
        throw new \LogicException('Факт отката статуса ещё не реализован (задача T-030).');
    }
}
