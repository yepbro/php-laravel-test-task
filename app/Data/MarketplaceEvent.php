<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Типизированный конверт входящего события вебхука MarketHub (T-009).
 *
 * Отображает поля верхнего уровня из task/marketplace-webhooks.md:
 * `id`, `type`, `created_at`, `data.order`. Построение DTO из сырых
 * байтов (валидация формата ISO 8601, типов полей, неизвестных типов
 * и статусов) — задача парсера T-021; здесь только типизированная
 * форма уже разобранного события, отделённая от Eloquent-моделей
 * (NFR-07: readonly DTO, данные из JSON не остаются mixed).
 *
 * `created_at` — момент времени события с исходным смещением; сравнение
 * только как моментов (FR-ORD-03), строковое сравнение запрещено.
 *
 * `$order` — null для событий без валидного снимка: неизвестный тип
 * события (снимок не проверяется, FR-WH-07) или неизвестный статус
 * у известного типа (полная валидация снимка не выполняется,
 * FR-WH-07/FR-WH-08); журнал хранит сырой payload отдельно.
 *
 * `$orderId` — `data.order.id` ПОСЛЕ структурных проверок шага 9 §7
 * (известный тип: объект `data.order` с строковыми `id`/`status`):
 * для неизвестного строкового статуса снимок не строится, но журнал
 * (T-026) записывает событие с `order_id` из конверта (шаг 10 §7).
 * Для неизвестного типа — null: `data.order` не проверяется и не
 * читается (FR-WH-06/07), журнал получает `order_id = NULL` (шаг 8).
 */
final readonly class MarketplaceEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public CarbonImmutable $createdAt,
        public ?OrderSnapshot $order,
        public ?string $orderId = null,
    ) {}
}
