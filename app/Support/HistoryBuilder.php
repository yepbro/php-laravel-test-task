<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\HistoryFact;
use App\Data\OrderSnapshot;
use App\Enums\OrderStatus;

/**
 * Построение фактов истории статусов из снимка (FR-HIS-01…FR-HIS-03,
 * скелет T-009, реализация T-030).
 *
 * История — «статусы, в которых побывал заказ, с моментами перехода
 * по данным маркетплейса». Из каждого валидного снимка (включая
 * устаревший, FR-HIS-02) выводятся следующие из него достоверные
 * факты; при откате статуса применённым снимком добавляется факт
 * ручного исправления (FR-HIS-03). Вставка в БД идемпотентна по
 * UNIQUE(order_id, status, at) — выполняет потребитель
 * (ProcessMarketplaceEvent, шаги 13c/13e §7); класс оперирует только
 * чистыми данными, без Eloquent. Поведенческие тесты — T-016
 * (HistoryBuilderTest).
 */
final class HistoryBuilder
{
    /**
     * Факты, достоверно следующие из снимка (FR-HIS-02).
     *
     * Полный снимок сообщает не только текущий, но и прошлые этапы
     * заказа: `created @ created_at` следует из каждого снимка;
     * `paid @ paid_at` / `cancelled @ cancelled_at` — если родное поле
     * не null (факт о прошлом статусе при null не следует — даты нет).
     * Факт текущего статуса — currentStatusFact(): `shipped`/
     * `delivered @ updated_at` (родного поля нет, T11), для `paid`/
     * `cancelled` — родное поле, при null fallback на `updated_at`
     * (FR-HIS-01; решение задачи 016: факт о текущем статусе создаётся
     * всегда, иначе fallback мёртв, а текущий статус выпадал бы из
     * истории).
     *
     * @return list<HistoryFact>
     */
    public function factsFromSnapshot(OrderSnapshot $snapshot): array
    {
        $facts = [new HistoryFact(OrderStatus::CREATED, $snapshot->createdAt)];

        if ($snapshot->paidAt !== null) {
            $facts[] = new HistoryFact(OrderStatus::PAID, $snapshot->paidAt);
        }

        if ($snapshot->cancelledAt !== null) {
            $facts[] = new HistoryFact(OrderStatus::CANCELLED, $snapshot->cancelledAt);
        }

        $currentStatusFact = $this->currentStatusFact($snapshot);

        if ($currentStatusFact !== null) {
            $facts[] = $currentStatusFact;
        }

        return $facts;
    }

    /**
     * Факт ручного исправления статуса (FR-HIS-03): применённый снимок
     * откатывает статус — `новый статус @ updated_at снимка` (момент
     * коррекции по данным маркетплейса).
     *
     * null, если переход не является откатом — ранг не убывает: прогресс,
     * повтор того же статуса, переход в терминальный (`shipped →
     * cancelled` — переход в терминальный, не откат). Переходы ИЗ
     * терминальных статусов сюда не доходят: они запрещены раньше
     * (FR-ORD-09, шаг 13d, T-029). Ранг — только механика истории
     * (T-004), не правило допустимости переходов.
     */
    public function rollbackFact(OrderStatus $previousStatus, OrderSnapshot $incoming): ?HistoryFact
    {
        if ($incoming->status->historyRank() >= $previousStatus->historyRank()) {
            return null;
        }

        return new HistoryFact($incoming->status, $incoming->updatedAt);
    }

    /**
     * Факт текущего статуса снимка, если он не выведен ранее из родных
     * полей (FR-HIS-01: null родного поля → updated_at; у shipped/
     * delivered родного поля нет).
     */
    private function currentStatusFact(OrderSnapshot $snapshot): ?HistoryFact
    {
        return match ($snapshot->status) {
            // created @ created_at уже добавлен в factsFromSnapshot().
            OrderStatus::CREATED => null,
            OrderStatus::PAID => $snapshot->paidAt === null
                ? new HistoryFact(OrderStatus::PAID, $snapshot->updatedAt)
                : null,
            OrderStatus::CANCELLED => $snapshot->cancelledAt === null
                ? new HistoryFact(OrderStatus::CANCELLED, $snapshot->updatedAt)
                : null,
            OrderStatus::SHIPPED, OrderStatus::DELIVERED => new HistoryFact($snapshot->status, $snapshot->updatedAt),
        };
    }
}
