<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;

/**
 * Матрица допустимости писем по текущему статусу заказа (§6.5/A-23,
 * скелет T-009).
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
 * Реализация — задача T-031; тесты — T-016/T-019.
 */
final class NotificationPolicy
{
    /**
     * Допустимо ли письмо типа $type при текущем статусе $currentStatus.
     *
     * @throws \LogicException пока не реализовано (задача T-031)
     */
    public function allows(NotificationType $type, OrderStatus $currentStatus): bool
    {
        throw new \LogicException('Матрица допустимости писем ещё не реализована (задача T-031).');
    }
}
