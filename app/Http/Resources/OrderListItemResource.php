<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\ApiDate;
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
            'created_at' => ApiDate::format($this->resource->placed_at),
            'updated_at' => ApiDate::format($this->resource->source_updated_at),
        ];
    }
}
