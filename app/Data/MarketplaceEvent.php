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
 */
final readonly class MarketplaceEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public CarbonImmutable $createdAt,
        public ?OrderSnapshot $order,
    ) {}
}
