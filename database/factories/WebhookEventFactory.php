<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventOutcome;
use App\Models\Order;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Событие вебхука MarketHub — запись журнала обработки (§6.3 ТЗ).
 *
 * По умолчанию — известный тип с исходом `applied`: payload — минимальный
 * валидный конверт, согласованный с `id`/`type`/`order_id` записи;
 * `payload_sha256` считается от финального payload автоматически (следует
 * и за явным переопределением payload) и переопределяется явно.
 * `processed_at = received_at`: все исходы enum'а — конечные состояния.
 *
 * «Настоящие» подписанные тела для HTTP-тестов берутся из `task/examples`
 * (задача T-015), фабрика — для подготовки журнала и данных БД.
 *
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Известные типы событий (§6.1 ТЗ; шаги 8–9 алгоритма §7).
     *
     * @var list<string>
     */
    public const KNOWN_TYPES = [
        'order.created',
        'order.paid',
        'order.shipped',
        'order.delivered',
        'order.cancelled',
        'order.updated',
    ];

    public function definition(): array
    {
        return [
            'id' => 'evt_' . (string) Str::ulid(),
            'type' => fake()->randomElement(self::KNOWN_TYPES),
            'order_id' => null,
            'signature_timestamp' => CarbonImmutable::now()->getTimestamp(),
            'payload' => static fn(array $attributes): string => self::payloadFor($attributes),
            'payload_sha256' => static fn(array $attributes): string => hash('sha256', self::stringOf($attributes['payload'])),
            'outcome' => EventOutcome::APPLIED,
            'reject_reason' => null,
            'deliveries' => 1,
            'received_at' => CarbonImmutable::now(),
            'last_received_at' => null,
            'processed_at' => static fn(array $attributes): ?CarbonImmutable => isset($attributes['received_at'])
                ? self::toCarbon($attributes['received_at'])
                : null,
        ];
    }

    /**
     * Известный тип (конкретный или случайный из KNOWN_TYPES) с исходом `applied`.
     */
    public function known(?string $type = null): static
    {
        return $this->state(function (array $attributes) use ($type): array {
            return [
                'type' => $type ?? fake()->randomElement(self::KNOWN_TYPES),
                'outcome' => EventOutcome::APPLIED,
                'reject_reason' => null,
            ];
        });
    }

    /**
     * Неизвестный тип — событие сохранено, но только как `ignored` (S-18/S-36;
     * `data.order` для таких событий не обязателен).
     */
    public function unknown(): static
    {
        return $this->state(function (array $attributes): array {
            return [
                'type' => fake()->randomElement(['order.refunded', 'order.returned', 'invoice.updated']),
                'outcome' => EventOutcome::IGNORED,
                'reject_reason' => null,
            ];
        });
    }

    /**
     * Свежий снимок не новее сохранённой версии (FR-ORD-02).
     */
    public function stale(): static
    {
        return $this->state(fn(): array => [
            'outcome' => EventOutcome::STALE,
            'reject_reason' => null,
        ]);
    }

    /**
     * Событие сохранено как `ignored`: например, известный тип с неизвестным
     * строковым статусом (S-36) — в отличие от `unknown()`, где неизвестен
     * сам тип события (S-18).
     */
    public function ignored(): static
    {
        return $this->state(fn(): array => [
            'outcome' => EventOutcome::IGNORED,
            'reject_reason' => null,
        ]);
    }

    /**
     * Свежий снимок отклонён из терминального статуса (FR-ORD-09, Q35).
     */
    public function rejected(): static
    {
        return $this->state(fn(): array => [
            'outcome' => EventOutcome::REJECTED,
            'reject_reason' => 'terminal_status',
        ]);
    }

    /**
     * Связать событие с заказом из снимка (мягкая ссылка без FK, §6.3).
     */
    public function forOrder(Order|string $order): static
    {
        return $this->state(function (array $attributes) use ($order): array {
            return ['order_id' => $order instanceof Order ? $order->id : $order];
        });
    }

    /**
     * Повторная доставка: `deliveries` > 1 и `last_received_at` после приёмки
     * (S-02/S-03).
     */
    public function redelivered(int $deliveries = 2): static
    {
        return $this->state(function (array $attributes) use ($deliveries): array {
            $receivedAt = self::toCarbon($attributes['received_at']);

            return [
                'deliveries' => $deliveries,
                'last_received_at' => $receivedAt->addMinutes(fake()->numberBetween(1, 60)),
            ];
        });
    }

    /**
     * Минимальный валидный конверт события, согласованный с атрибутами записи.
     *
     * @param array<array-key, mixed> $attributes
     */
    private static function payloadFor(array $attributes): string
    {
        $orderId = $attributes['order_id'] ?? null;

        $payload = [
            'id' => self::stringOf($attributes['id']),
            'type' => self::stringOf($attributes['type']),
            'created_at' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:sP'),
            'data' => [
                'order' => [
                    'id' => is_string($orderId) ? $orderId : 'ord_' . strtoupper(Str::random(8)),
                ],
            ],
        ];

        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
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
}
