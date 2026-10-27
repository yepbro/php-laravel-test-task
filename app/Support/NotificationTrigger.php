<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;

/**
 * Правило триггера outbox-уведомления (§6.5 A-22/A-28 + §7 шаг 13f,
 * реализация T-032).
 *
 * Письмо создаётся только по ПРИМЕНЁННОМУ снимку, который действительно
 * меняет статус заказа на `paid`, `shipped` или `cancelled`; `duplicate`,
 * `stale`, `ignored` и `rejected` до триггера не доходят (их ветки
 * заканчиваются раньше шагов 13e–13f). Письмо-исправление при откате
 * статуса не отправляется (Q28, A-28): свежая коррекция `shipped → paid`
 * меняет состояние и историю, но не запускает повторное письмо «оплачен».
 *
 * Чистая функция над статусами: тип письма определяется НОВЫМ статусом
 * заказа (не `event.type` — совпадение типа события и статуса ТЗ не
 * вводит; не фактами истории — `order.shipped` первым создаёт только
 * shipped-письмо, хотя paid-факт восстанавливается по `paid_at`, A-22).
 * Первый снимок (заказа не было) — создание заказа со статусом
 * paid/shipped/cancelled триггер проходит: смена «не существует →
 * существует в статусе» полноправна (S-06/S-07).
 *
 * Ранги OrderStatus — та же механика обнаружения отката, что и в
 * истории (FR-HIS-03, HistoryBuilder::rollbackFact): откат — строгая
 * убыль ранга; прогресс и переход в терминальный статус (`shipped →
 * cancelled`) откатом не являются. Переходы ИЗ терминальных статусов
 * сюда не доходят — запрещены раньше (FR-ORD-09, шаг 13d, T-029).
 * Поведенческие тесты — T-032 (NotificationTriggerTest).
 */
final class NotificationTrigger
{
    /**
     * Тип письма, которое применённый снимок обязан создать, или null —
     * триггер не сработал.
     *
     * @param OrderStatus|null $previousStatus статус заказа до применения
     *        (null — первый снимок: заказ только создаётся)
     */
    public function typeFor(?OrderStatus $previousStatus, OrderStatus $newStatus): ?NotificationType
    {
        // Обновление данных при том же статусе (включая терминальный,
        // S-31) триггером не является — смены статуса нет.
        if ($previousStatus === $newStatus) {
            return null;
        }

        // Откат статуса (Q28): письмо-исправление не отправляется.
        // prev = null (первый снимок) откатом быть не может.
        if (
            $previousStatus !== null
            && $newStatus->historyRank() < $previousStatus->historyRank()
        ) {
            return null;
        }

        // Тип — из нового статуса; у created/delivered писем нет (§6.5).
        return match ($newStatus) {
            OrderStatus::PAID => NotificationType::PAID,
            OrderStatus::SHIPPED => NotificationType::SHIPPED,
            OrderStatus::CANCELLED => NotificationType::CANCELLED,
            OrderStatus::CREATED, OrderStatus::DELIVERED => null,
        };
    }
}
