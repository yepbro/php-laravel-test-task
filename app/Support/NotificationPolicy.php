<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;

/**
 * Матрица допустимости писем по текущему статусу заказа (§6.5/A-23,
 * скелет T-009, реализация T-031).
 *
 * Письмо ↓ / статус → | created | paid | shipped | delivered | cancelled
 * --------------------|---------|------|---------|----------|----------
 * paid                |    ✗    |  ✓   |    ✓    |    ✓     |    ✗
 * shipped             |    ✗    |  ✗   |    ✓    |    ✓     |    ✗
 * cancelled           |    ✗    |  ✗   |    ✗    |    ✗     |    ✓
 *
 * Проверяется непосредственно перед отправкой под блокировкой заказа
 * (FR-MAIL-03): статус мог смениться после постановки в очередь;
 * недопустимое письмо — `skipped (superseded)`. Матрица хранится как
 * данные, чтобы правило легко тестировалось и менялось (принцип §10).
 * Поведенческие тесты — T-016 (NotificationPolicyTest).
 */
final class NotificationPolicy
{
    /**
     * Допустимо ли письмо типа $type при текущем статусе $currentStatus.
     *
     * Смысл ячеек: письмо о статусе допустимо, пока заказ дошёл до
     * этого статуса или продвинулся дальше (`paid → shipped →
     * delivered`), и не был отменён; `cancelled`-письмо — только в
     * `cancelled`; в `created` недопустимо любое. Ранги OrderStatus
     * здесь сознательно не используются — это механика истории
     * (T-004), а не правило писем; матрица задана явно по §6.5.
     * Отсутствие письма-исправления при откате статуса (Q28) —
     * правило ТРИГГЕРА создания outbox (уведомление только при СМЕНЕ
     * статуса, T-032) и дедупликации, а не матрицы момента отправки:
     * `allows(paid, paid) = true` не противоречит Q28 — повторное
     * письмо не будет создано триггером (DECISIONS.md, задача 016).
     */
    public function allows(NotificationType $type, OrderStatus $currentStatus): bool
    {
        $allowedStatuses = match ($type) {
            NotificationType::PAID => [
                OrderStatus::PAID,
                OrderStatus::SHIPPED,
                OrderStatus::DELIVERED,
            ],
            NotificationType::SHIPPED => [
                OrderStatus::SHIPPED,
                OrderStatus::DELIVERED,
            ],
            NotificationType::CANCELLED => [
                OrderStatus::CANCELLED,
            ],
        };

        return in_array($currentStatus, $allowedStatuses, true);
    }
}
