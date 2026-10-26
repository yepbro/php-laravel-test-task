<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Заказ MarketHub.
 *
 * Значения по умолчанию — консистентный заказ в статусе `created` (§6.3 ТЗ):
 * nullable-поля NULL, все три shipment-поля NULL одновременно, суммы — строки
 * с двумя знаками (генерация через BigDecimal, без float), даты — UTC.
 * `customer_email_normalized` и `snapshot` согласованы с остальными полями
 * автоматически (замыкания видят финальные атрибуты) и переопределяются явно.
 *
 * States применяют цельные наборы: каждый статусовый state выставляет свой
 * статус, свои даты MarketHub и пересчитывает версию снимка
 * (`source_updated_at` + 1 секунда на `version_event_created_at`, как в
 * конверте события). `cancelled()` не трогает `paid_at` — неоплаченная и
 * оплаченная отмена получаются цепочками `cancelled()` / `paid()->cancelled()`
 * (сценарии S-09/S-08).
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Службы доставки для shipment-полей (§6.3: все три NULL ⇔ shipment = null).
     *
     * @var list<string>
     */
    private const CARRIERS = ['СДЭК', 'Почта России', 'Boxberry', 'DPD'];

    public function definition(): array
    {
        $placedAt = CarbonImmutable::now()
            ->subDays(fake()->numberBetween(0, 30))
            ->subMinutes(fake()->numberBetween(0, 1439));

        return [
            'id' => 'ord_' . strtoupper(Str::random(8)),
            'number' => 'MH-' . fake()->numberBetween(1000000, 9999999),
            'status' => OrderStatus::CREATED,
            'currency' => fake()->randomElement(['RUB', 'USD', 'EUR']),
            'customer_email' => fake()->safeEmail(),
            'customer_email_normalized' => static fn(array $attributes): string => mb_strtolower(trim(self::stringOf($attributes['customer_email']))),
            'customer_name' => fake()->name(),
            'customer_phone' => '+7' . fake()->numerify('9#########'),
            'delivery_price' => self::money(fake()->numberBetween(0, 200000)),
            'discount' => self::money(fake()->numberBetween(0, 100000)),
            'total' => self::money(fake()->numberBetween(100, 1000000)),
            'shipment_carrier' => null,
            'shipment_tracking_number' => null,
            'shipment_tracking_url' => null,
            'placed_at' => $placedAt,
            'source_updated_at' => $placedAt,
            'paid_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'version_event_created_at' => $placedAt->addSecond(),
            'version_event_id' => 'evt_' . (string) Str::ulid(),
            'snapshot' => static fn(array $attributes): array => self::snapshotFromAttributes($attributes),
        ];
    }

    /**
     * Заказ оформлен: даты MarketHub совпадают, письма/доставка не заданы.
     */
    public function created(): static
    {
        return $this->state(function (array $attributes): array {
            $placedAt = self::toCarbon($attributes['placed_at']);

            return [
                'status' => OrderStatus::CREATED,
                'paid_at' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'shipment_carrier' => null,
                'shipment_tracking_number' => null,
                'shipment_tracking_url' => null,
                ...self::versionAt($placedAt),
            ];
        });
    }

    /**
     * Оплаченный заказ: `paid_at` после оформления, shipment не задан.
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes): array {
            $placedAt = self::toCarbon($attributes['placed_at']);
            $paidAt = isset($attributes['paid_at'])
                ? self::toCarbon($attributes['paid_at'])
                : $placedAt->addMinutes(fake()->numberBetween(1, 1440));

            return [
                'status' => OrderStatus::PAID,
                'paid_at' => $paidAt,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'shipment_carrier' => null,
                'shipment_tracking_number' => null,
                'shipment_tracking_url' => null,
                ...self::versionAt($paidAt),
            ];
        });
    }

    /**
     * Передан в доставку: `paid_at` заполнен (S-07), shipment-тройка согласована
     * (URL содержит трек-номер). Трек-номер можно задать явно.
     */
    public function shipped(?string $trackingNumber = null): static
    {
        return $this->state(function (array $attributes) use ($trackingNumber): array {
            $paidAt = isset($attributes['paid_at'])
                ? self::toCarbon($attributes['paid_at'])
                : self::toCarbon($attributes['placed_at'])->addMinutes(fake()->numberBetween(1, 60));
            $shippedAt = $paidAt->addMinutes(fake()->numberBetween(1, 720));

            $trackingNumber ??= (string) fake()->numberBetween(1000000000, 9999999999);

            return [
                'status' => OrderStatus::SHIPPED,
                'paid_at' => $paidAt,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'shipment_carrier' => fake()->randomElement(self::CARRIERS),
                'shipment_tracking_number' => $trackingNumber,
                'shipment_tracking_url' => 'https://example.com/track/' . $trackingNumber,
                ...self::versionAt($shippedAt),
            ];
        });
    }

    /**
     * Доставлен (терминальный, Q35): оплачен, shipment заполнен — существующий
     * (после `shipped()`) сохраняется, иначе генерируется.
     */
    public function delivered(): static
    {
        return $this->state(function (array $attributes): array {
            $paidAt = isset($attributes['paid_at'])
                ? self::toCarbon($attributes['paid_at'])
                : self::toCarbon($attributes['placed_at'])->addMinutes(fake()->numberBetween(1, 60));
            $deliveredAt = $paidAt->addMinutes(fake()->numberBetween(61, 4320));

            // После shipped() версия уже продвинута — не откатываем хронологию.
            $existingSource = isset($attributes['source_updated_at'])
                ? self::toCarbon($attributes['source_updated_at'])
                : null;

            if ($existingSource !== null && $existingSource->greaterThanOrEqualTo($deliveredAt)) {
                $deliveredAt = $existingSource->addMinute();
            }

            $existingTracking = $attributes['shipment_tracking_number'] ?? null;
            $existingCarrier = $attributes['shipment_carrier'] ?? null;
            $existingUrl = $attributes['shipment_tracking_url'] ?? null;

            if (is_string($existingTracking) && $existingTracking !== '') {
                $shipment = [
                    'shipment_carrier' => is_string($existingCarrier) && $existingCarrier !== ''
                        ? $existingCarrier
                        : fake()->randomElement(self::CARRIERS),
                    'shipment_tracking_number' => $existingTracking,
                    'shipment_tracking_url' => is_string($existingUrl) && $existingUrl !== ''
                        ? $existingUrl
                        : 'https://example.com/track/' . $existingTracking,
                ];
            } else {
                $trackingNumber = (string) fake()->numberBetween(1000000000, 9999999999);

                $shipment = [
                    'shipment_carrier' => fake()->randomElement(self::CARRIERS),
                    'shipment_tracking_number' => $trackingNumber,
                    'shipment_tracking_url' => 'https://example.com/track/' . $trackingNumber,
                ];
            }

            return [
                'status' => OrderStatus::DELIVERED,
                'paid_at' => $paidAt,
                'cancelled_at' => null,
                'cancel_reason' => null,
                ...$shipment,
                ...self::versionAt($deliveredAt),
            ];
        });
    }

    /**
     * Отменён (терминальный, Q35): `cancelled_at` и причина заданы, shipment
     * очищен (отмена до отправки). `paid_at` не трогается: по умолчанию отмена
     * неоплаченного (S-09), `paid()->cancelled()` — оплаченного (S-08).
     */
    public function cancelled(): static
    {
        return $this->state(function (array $attributes): array {
            $base = isset($attributes['paid_at'])
                ? self::toCarbon($attributes['paid_at'])
                : self::toCarbon($attributes['placed_at']);
            $cancelledAt = $base->addMinutes(fake()->numberBetween(1, 1440));

            return [
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => $cancelledAt,
                'cancel_reason' => fake()->randomElement([
                    'Заказ отменён покупателем',
                    'Заказ не был оплачен вовремя',
                    'Товар закончился на складе',
                ]),
                'shipment_carrier' => null,
                'shipment_tracking_number' => null,
                'shipment_tracking_url' => null,
                ...self::versionAt($cancelledAt),
            ];
        });
    }

    /**
     * Заказ без телефона покупателя (S-26).
     */
    public function withoutPhone(): static
    {
        return $this->state(fn(): array => ['customer_phone' => null]);
    }

    /**
     * Очистить shipment-тройку без смены статуса — например, `shipped()` без
     * отправления для проверки skip-ветки `no_shipment` (FR-MAIL-08).
     */
    public function withoutShipment(): static
    {
        return $this->state(fn(): array => [
            'shipment_carrier' => null,
            'shipment_tracking_number' => null,
            'shipment_tracking_url' => null,
        ]);
    }

    /**
     * Позиции заказа: `position` 1..N (UNIQUE(order_id, position)) и синхронный
     * `snapshot['items']`. При `$count < 1` — no-op (позиции не создаются).
     */
    public function withItems(int $count = 2): static
    {
        return $this->afterCreating(function (Order $order) use ($count): void {
            if ($count < 1) {
                return;
            }

            $items = OrderItem::factory()->createMany(
                array_map(
                    static fn(int $position): array => [
                        'order_id' => $order->id,
                        'position' => $position,
                    ],
                    range(1, $count),
                ),
            );

            $order->snapshot = array_merge($order->snapshot ?? [], [
                'items' => $items
                    ->map(static fn(OrderItem $item): array => [
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                    ])
                    ->all(),
            ]);
            $order->save();
        });
    }

    /**
     * Факты истории, следующие из снимка заказа (FR-HIS-02): `created` всегда,
     * `paid`/`cancelled` — по filled-полям, `shipped`/`delivered` — по статусу
     * со временем `source_updated_at` (FR-HIS-01).
     */
    public function withHistory(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $facts = [
                ['status' => OrderStatus::CREATED, 'at' => $order->placed_at],
            ];

            if ($order->paid_at !== null) {
                $facts[] = ['status' => OrderStatus::PAID, 'at' => $order->paid_at];
            }

            if ($order->cancelled_at !== null) {
                $facts[] = ['status' => OrderStatus::CANCELLED, 'at' => $order->cancelled_at];
            }

            if ($order->status === OrderStatus::SHIPPED || $order->status === OrderStatus::DELIVERED) {
                $facts[] = ['status' => $order->status, 'at' => $order->source_updated_at];
            }

            $order->history()->createMany(array_map(
                static fn(array $fact): array => $fact + ['source_event_id' => 'evt_' . (string) Str::ulid()],
                $facts,
            ));
        });
    }

    /**
     * Снимок в форме снимка MarketHub (task/examples/order-*.json), согласованный
     * с полями записи на момент создания: суммы — строки с двумя знаками, даты —
     * ISO 8601 UTC, `items` пуст, пока не вызван `withItems()`.
     *
     * @param array<array-key, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function snapshotFromAttributes(array $attributes): array
    {
        $shipment = $attributes['shipment_carrier'] !== null
            ? [
                'carrier' => self::stringOf($attributes['shipment_carrier']),
                'tracking_number' => self::stringOf($attributes['shipment_tracking_number']),
                'tracking_url' => self::stringOf($attributes['shipment_tracking_url']),
            ]
            : null;

        return [
            'id' => self::stringOf($attributes['id']),
            'number' => self::stringOf($attributes['number']),
            'status' => self::statusValue($attributes['status']),
            'currency' => self::stringOf($attributes['currency']),
            'customer' => [
                'email' => self::stringOf($attributes['customer_email']),
                'name' => self::stringOf($attributes['customer_name']),
                'phone' => $attributes['customer_phone'] ?? null,
            ],
            'items' => [],
            'delivery_price' => self::moneyOf($attributes['delivery_price']),
            'discount' => self::moneyOf($attributes['discount']),
            'total' => self::moneyOf($attributes['total']),
            'shipment' => $shipment,
            'paid_at' => isset($attributes['paid_at']) ? self::isoUtc($attributes['paid_at']) : null,
            'cancelled_at' => isset($attributes['cancelled_at']) ? self::isoUtc($attributes['cancelled_at']) : null,
            'cancel_reason' => $attributes['cancel_reason'] ?? null,
            'created_at' => self::isoUtc($attributes['placed_at']),
            'updated_at' => self::isoUtc($attributes['source_updated_at']),
        ];
    }

    /**
     * Версия снимка для момента `at`: `version_event_created_at` на секунду
     * позже `source_updated_at`, как в конверте события (task/examples).
     *
     * @return array{source_updated_at: CarbonImmutable, version_event_created_at: CarbonImmutable, version_event_id: string}
     */
    private static function versionAt(CarbonImmutable $at): array
    {
        return [
            'source_updated_at' => $at,
            'version_event_created_at' => $at->addSecond(),
            'version_event_id' => 'evt_' . (string) Str::ulid(),
        ];
    }

    /**
     * Копейки → строка с двумя знаками через BigDecimal (без float, §6.3 ТЗ).
     */
    private static function money(int $kopecks): string
    {
        return (string) BigDecimal::of($kopecks)->dividedBy(100, 2);
    }

    /**
     * Нормализация денежного значения (в т.ч. из переопределений) в строку
     * с двумя знаками.
     */
    private static function moneyOf(mixed $value): string
    {
        if (is_int($value) || is_string($value) || is_float($value)) {
            return (string) BigDecimal::of((string) $value)->toScale(2);
        }

        throw new InvalidArgumentException('Ожидалось денежное значение (int|float|string).');
    }

    /**
     * Строковое значение статуса (enum или строка из переопределения).
     */
    private static function statusValue(mixed $status): string
    {
        if ($status instanceof OrderStatus) {
            return $status->value;
        }

        if (is_string($status)) {
            return $status;
        }

        throw new InvalidArgumentException('Ожидался статус (OrderStatus|string).');
    }

    /**
     * Строковое значение атрибута (значения фабрики — строки; переопределение
     * нестроковым значением — ошибка подготовки тестовых данных).
     */
    private static function stringOf(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Ожидалась строка.');
    }

    /**
     * Дата ISO 8601 в UTC (формат смещения — как в примерах конвертов).
     */
    private static function isoUtc(mixed $date): string
    {
        return self::toCarbon($date)->utc()->format('Y-m-d\TH:i:sP');
    }

    /**
     * Приведение значения атрибута-даты (Carbon, DateTime или строка из
     * переопределения) к CarbonImmutable.
     */
    private static function toCarbon(mixed $date): CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date;
        }

        if ($date instanceof DateTimeInterface || is_string($date)) {
            return CarbonImmutable::parse($date);
        }

        throw new InvalidArgumentException('Ожидалась дата (CarbonImmutable|DateTimeInterface|string).');
    }
}
