<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка заказа GET /api/orders/{id} (FR-API-05, схема Order из
 * task/openapi.yaml).
 *
 * Все required-поля, включая nullable (`customer.phone`, `shipment`,
 * `paid_at`, `cancelled_at`, `cancel_reason`), присутствуют всегда —
 * как null. `created_at`/`updated_at` — даты маркетплейса (FR-API-07).
 * Позиции отдаются в порядке position, история — по FR-HIS-04
 * (`at ASC`, при равенстве — ранг статуса, затем `id`); сериализация
 * целиком по контракту проверяется в T-037/T-038.
 */
final class OrderResource extends JsonResource
{
    /** @var Order */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'customer' => [
                'email' => $order->customer_email,
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
            ],
            'currency' => $order->currency,
            'items' => $order->items
                ->map(
                    static fn(OrderItem $item): array => [
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'subtotal' => self::subtotal($item),
                    ],
                )
                ->all(),
            'delivery_price' => $order->delivery_price,
            'discount' => $order->discount,
            'total' => $order->total,
            'shipment' => $order->shipment_carrier !== null ? [
                'carrier' => $order->shipment_carrier,
                'tracking_number' => $order->shipment_tracking_number,
                'tracking_url' => $order->shipment_tracking_url,
            ] : null,
            'paid_at' => self::formatDate($order->paid_at),
            'cancelled_at' => self::formatDate($order->cancelled_at),
            'cancel_reason' => $order->cancel_reason,
            'created_at' => self::formatDate($order->placed_at),
            'updated_at' => self::formatDate($order->source_updated_at),
            // FR-HIS-04: at ASC, при равенстве — ранг статуса, затем id.
            // Ранги — в OrderStatus::historyRank(), поэтому сортировка
            // коллекцией, а не в SQL; values() перенумеровывает ключи,
            // иначе история сериализовалась бы JSON-объектом.
            'history' => $order->history
                ->sort(
                    static fn(OrderStatusHistory $a, OrderStatusHistory $b): int => [
                        $a->at->getTimestamp(),
                        $a->status->historyRank(),
                        $a->id,
                    ] <=> [
                        $b->at->getTimestamp(),
                        $b->status->historyRank(),
                        $b->id,
                    ],
                )
                ->values()
                ->map(
                    static fn(OrderStatusHistory $entry): array => [
                        'status' => $entry->status->value,
                        'at' => self::formatDate($entry->at),
                    ],
                )
                ->all(),
        ];
    }

    /**
     * Дата ISO 8601 в UTC без долей секунды (FR-API-06, A-21):
     * `2026-10-22T06:40:03+00:00`.
     */
    private static function formatDate(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->format('Y-m-d\TH:i:sP');
    }

    /**
     * Стоимость позиции `price × quantity` строкой с двумя знаками
     * (FR-API-05/FR-API-06).
     *
     * Точное умножение BigDecimal — без float и без округления: цена
     * всегда строка с двумя знаками, количество — целое, произведение
     * уже имеет два знака. Унификация с Money::subtotal (T-022) и
     * контрактная проверка сериализации — T-037.
     */
    private static function subtotal(OrderItem $item): string
    {
        return (string) BigDecimal::of($item->price)
            ->multipliedBy($item->quantity)
            ->toScale(2);
    }
}
