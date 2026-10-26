<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Позиция заказа.
 *
 * `position` — следующая свободная позиция заказа (UNIQUE(order_id, position)):
 * при создании позиций по одной инкрементируется автоматически. Для пакета
 * `count()` в рамках одного заказа передавайте позиции явно (Laravel разрешает
 * атрибуты всего батча до вставки в БД) — `OrderFactory::withItems()` делает
 * это самостоятельно.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'position' => static function (array $attributes): int {
                $max = OrderItem::query()
                    ->where('order_id', $attributes['order_id'])
                    ->max('position');

                return (is_int($max) ? $max : 0) + 1;
            },
            'sku' => fake()->bothify('SKU-????-####'),
            'name' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => (string) BigDecimal::of(fake()->numberBetween(10000, 5000000))->dividedBy(100, 2),
        ];
    }
}
