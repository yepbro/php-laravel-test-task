<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция заказа.
 *
 * Собственных системных меток времени у таблицы нет (T-003): позиция —
 * часть снимка заказа и живёт ровно столько же (cascade delete).
 *
 * @property int $id Идентификатор позиции
 * @property string $order_id Идентификатор заказа MarketHub
 * @property int $position Позиция в заказе (с 1)
 * @property string $sku Артикул товара
 * @property string $name Название товара
 * @property int $quantity Количество
 * @property string $price Цена за единицу (строка с двумя знаками)
 *
 * @property-read Order $order Заказ, которому принадлежит позиция
 */
class OrderItem extends Model
{
    /**
     * Таблица не имеет created_at/updated_at.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'position',
        'sku',
        'name',
        'quantity',
        'price',
    ];

    /**
     * Цена — `decimal:2` (строка с двумя знаками, без float); subtotal
     * (`price × quantity`) не хранится и вычисляется при выводе (§6.3 ТЗ).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    /**
     * Заказ, которому принадлежит позиция.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
