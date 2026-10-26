<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка заказов GET /api/orders (FR-API-04, схема OrderListItem
 * из task/openapi.yaml).
 *
 * `created_at`/`updated_at` — даты маркетплейса (placed_at/
 * source_updated_at, FR-API-07), системные метки записи наружу не
 * выходят. Формат дат — UTC +00:00 без долей секунды (FR-API-06, A-21).
 */
final class OrderListItemResource extends JsonResource
{
    /** @var Order */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'number' => $this->resource->number,
            'status' => $this->resource->status->value,
            'customer' => [
                'email' => $this->resource->customer_email,
                'name' => $this->resource->customer_name,
            ],
            'total' => $this->resource->total,
            'currency' => $this->resource->currency,
            'created_at' => self::formatDate($this->resource->placed_at),
            'updated_at' => self::formatDate($this->resource->source_updated_at),
        ];
    }

    /**
     * Дата ISO 8601 в UTC без долей секунды (FR-API-06, A-21):
     * `2026-10-22T06:40:03+00:00`.
     */
    private static function formatDate(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:sP');
    }
}
