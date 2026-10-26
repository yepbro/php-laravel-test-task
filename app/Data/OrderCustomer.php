<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Данные покупателя из снимка заказа MarketHub (T-009).
 *
 * Поля — из task/marketplace-webhooks.md (`customer.{email,name,phone}`);
 * `phone` — explicit null: событие прямо содержит `"phone": null`
 * (пример order-cancelled.json), отсутствие и null не различаются.
 */
final readonly class OrderCustomer
{
    public function __construct(
        public string $email,
        public string $name,
        public ?string $phone,
    ) {}
}
