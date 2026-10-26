<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Позиция заказа из снимка MarketHub (T-009).
 *
 * Поля — из task/marketplace-webhooks.md (`items[].{sku,name,quantity,price}`).
 * `price` — денежная сумма строкой с двумя знаками (`"1290.00"`); float
 * в денежных значениях запрещён (NFR-02), арифметика — задача T-022
 * (Money::subtotal()). Ограничение `quantity ≥ 1` проверяет парсер T-021,
 * здесь только тип.
 */
final readonly class OrderItemSnapshot
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        public string $price,
    ) {}
}
