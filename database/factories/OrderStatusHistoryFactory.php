<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Запись истории статусов заказа (§6.4 ТЗ).
 *
 * UNIQUE(order_id, status, at) не нарушается типовыми сценариями: у каждой
 * записи свой `at` и `source_event_id`. Для набора фактов, следующих из
 * состояния заказа, — `OrderFactory::withHistory()` (все факты сразу) или
 * `forOrder()` (факт текущего статуса по FR-HIS-01).
 *
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        /** @var OrderStatus $status */
        $status = fake()->randomElement(OrderStatus::cases());

        return [
            'order_id' => Order::factory(),
            'status' => $status,
            'at' => CarbonImmutable::now()->subMinutes(fake()->numberBetween(0, 525600)),
            'source_event_id' => 'evt_' . (string) Str::ulid(),
        ];
    }

    /**
     * Факт текущего статуса заказа: момент перехода — из «родного» поля снимка
     * по FR-HIS-01 (`created` → placed_at, `paid` → paid_at, `cancelled` →
     * cancelled_at, `shipped`/`delivered` → updated_at; если родное поле NULL —
     * `source_updated_at`).
     */
    public function forOrder(Order $order): static
    {
        return $this->state(function (array $attributes) use ($order): array {
            $at = match ($order->status) {
                OrderStatus::CREATED => $order->placed_at,
                OrderStatus::PAID => $order->paid_at ?? $order->source_updated_at,
                OrderStatus::CANCELLED => $order->cancelled_at ?? $order->source_updated_at,
                OrderStatus::SHIPPED, OrderStatus::DELIVERED => $order->source_updated_at,
            };

            return [
                'order_id' => $order->id,
                'status' => $order->status,
                'at' => $at,
            ];
        });
    }
}
