<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Order;
use App\Models\OrderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Уведомление покупателя — запись transactional outbox (§6.5 ТЗ).
 *
 * По умолчанию — `pending`-запись для заказа, согласованного с типом письма
 * по матрице допустимости §6.5 (paid → оплаченный, shipped → отправленный,
 * cancelled → отменённый); `dedupe_key` построен по FR-MAIL-02 и для
 * `shipped` использует трек-номер этого же заказа. `recipient`/`subject`
 * по умолчанию NULL: тело письма строится по текущему состоянию заказа
 * в момент отправки (§6.5), а не в момент создания outbox-записи — state
 * `sent()` заполняет их из заказа.
 *
 * @extends Factory<OrderNotification>
 */
class OrderNotificationFactory extends Factory
{
    public function definition(): array
    {
        /** @var NotificationType $type */
        $type = fake()->randomElement(NotificationType::cases());

        $orderId = 'ord_' . strtoupper(Str::random(8));
        $trackingNumber = (string) fake()->numberBetween(1000000000, 9999999999);

        $order = match ($type) {
            NotificationType::PAID => Order::factory()->paid(),
            NotificationType::SHIPPED => Order::factory()->shipped($trackingNumber),
            NotificationType::CANCELLED => Order::factory()->cancelled(),
        };

        return [
            'order_id' => $order->state(['id' => $orderId]),
            'type' => $type,
            // Ключ строится от финальных order_id/type (замыкание видит уже
            // созданный вложенный заказ или переопределение), как в forOrder().
            'dedupe_key' => static function (array $attributes) use ($type, $orderId, $trackingNumber): string {
                $finalType = self::notificationType($attributes['type'] ?? $type);

                return self::dedupeKeyFor($finalType, self::resolveOrder($attributes, $orderId), $trackingNumber);
            },
            'status' => NotificationStatus::PENDING,
            'skip_reason' => null,
            'recipient' => null,
            'subject' => null,
            'attempts' => 0,
            'source_event_id' => 'evt_' . (string) Str::ulid(),
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    /**
     * Запись создана в outbox и ждёт постановки/отправки (FR-MAIL-01).
     */
    public function pending(): static
    {
        return $this->state(fn(): array => [
            'status' => NotificationStatus::PENDING,
            'skip_reason' => null,
            'last_error' => null,
            'sent_at' => null,
            'attempts' => 0,
        ]);
    }

    /**
     * Захвачено воркером перед отправкой (FR-MAIL-04).
     */
    public function sending(): static
    {
        return $this->state(fn(): array => [
            'status' => NotificationStatus::SENDING,
            'skip_reason' => null,
            'last_error' => null,
            'sent_at' => null,
            'attempts' => 0,
        ]);
    }

    /**
     * Отправлено: `sent_at`/`attempts` заполнены, получатель и тема — дословно
     * по §6.5 из текущего состояния заказа (если не заданы явно).
     */
    public function sent(): static
    {
        return $this
            ->state(fn(): array => [
                'status' => NotificationStatus::SENT,
                'skip_reason' => null,
                'last_error' => null,
                'sent_at' => CarbonImmutable::now(),
                'attempts' => 1,
            ])
            ->afterCreating(function (OrderNotification $notification): void {
                $order = $notification->order;

                $notification->recipient ??= $order->customer_email;
                $notification->subject ??= self::subjectFor($notification->type, $order->number);

                $notification->save();
            });
    }

    /**
     * Отправка пропущена по правилам допустимости; `dedupe_key` освобождён
     * (FR-MAIL-03). Причина — например, `superseded` (S-10), `no_shipment`
     * (FR-MAIL-08), `invalid_recipient` (FR-MAIL-07).
     */
    public function skipped(string $reason = 'superseded'): static
    {
        return $this->state(fn(): array => [
            'status' => NotificationStatus::SKIPPED,
            'skip_reason' => $reason,
            'dedupe_key' => null,
            'last_error' => null,
            'sent_at' => null,
            'attempts' => 0,
        ]);
    }

    /**
     * Попытки исчерпаны (FR-MAIL-05: максимум 5 с backoff).
     */
    public function failed(): static
    {
        return $this->state(fn(): array => [
            'status' => NotificationStatus::FAILED,
            'skip_reason' => null,
            'last_error' => 'SMTP недоступен: таймаут соединения',
            'sent_at' => null,
            'attempts' => 5,
        ]);
    }

    /**
     * Явно отключить дедупликацию (UNIQUE разрешает NULL).
     */
    public function withoutDedupeKey(): static
    {
        return $this->state(fn(): array => ['dedupe_key' => null]);
    }

    /**
     * Уведомление для существующего заказа: `dedupe_key` пересчитывается по
     * FR-MAIL-02 от этого заказа (для `shipped` — его трек-номер; если у заказа
     * нет shipment, хвост ключа окажется пустым — нестандартная комбинация,
     * соответствующая skip-ветке FR-MAIL-08) с учётом финального типа записи.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(function (array $attributes) use ($order): array {
            return [
                'order_id' => $order->id,
                'dedupe_key' => static function (array $attributes) use ($order): string {
                    $type = self::notificationType($attributes['type']);

                    return self::dedupeKeyFor($type, $order);
                },
            ];
        });
    }

    /**
     * Ключ дедупликации по FR-MAIL-02: `{order_id}:{type}` для paid/cancelled,
     * `{order_id}:shipped:{tracking_number}` для shipped.
     *
     * @param string|null $fallbackTracking трек-номер, вшитый во вложенный заказ
     * фабрики (для shipped), если заказать создала сама фабрика.
     */
    private static function dedupeKeyFor(NotificationType $type, Order $order, ?string $fallbackTracking = null): string
    {
        if ($type !== NotificationType::SHIPPED) {
            return "{$order->id}:{$type->value}";
        }

        $trackingNumber = $order->shipment_tracking_number ?? $fallbackTracking ?? '';

        return "{$order->id}:shipped:{$trackingNumber}";
    }

    /**
     * Заказ из финальных атрибутов записи: созданный вложенной фабрикой
     * (тогда это предгенерированный id), переданный моделью или id
     * существующего заказа; последний случай — загрузка из БД.
     *
     * @param array<array-key, mixed> $attributes
     */
    private static function resolveOrder(array $attributes, string $fallbackOrderId): Order
    {
        $orderId = $attributes['order_id'] ?? null;

        if ($orderId instanceof Order) {
            return $orderId;
        }

        if (is_string($orderId)) {
            return Order::query()->findOrFail($orderId);
        }

        // Вложенная фабрика уже создала заказ с предгенерированным id.
        return Order::query()->findOrFail($fallbackOrderId);
    }

    /**
     * Тема письма — дословно из §6.5 ТЗ (символ «№» — U+2116).
     */
    private static function subjectFor(NotificationType $type, string $number): string
    {
        return match ($type) {
            NotificationType::PAID => "Заказ №{$number} оплачен",
            NotificationType::SHIPPED => "Заказ №{$number} передан в доставку",
            NotificationType::CANCELLED => "Заказ №{$number} отменён",
        };
    }

    /**
     * Тип письма из атрибута (enum или строка из переопределения).
     */
    private static function notificationType(mixed $value): NotificationType
    {
        if ($value instanceof NotificationType) {
            return $value;
        }

        if (is_string($value)) {
            return NotificationType::from($value);
        }

        throw new InvalidArgumentException('Ожидался тип письма (NotificationType|string).');
    }
}
