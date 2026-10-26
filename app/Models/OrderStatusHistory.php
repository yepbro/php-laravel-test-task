<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись истории статусов заказа.
 *
 * Факт «заказ находился в статусе $status с момента $at» по данным
 * MarketHub (§6.4 ТЗ): момент перехода берётся из полей снимка, а не из
 * времени получения вебхука. Собственных системных меток у таблицы нет.
 *
 * @property int $id Идентификатор записи истории
 * @property string $order_id Идентификатор заказа MarketHub
 * @property OrderStatus $status Статус, в котором находился заказ
 * @property CarbonImmutable $at Момент перехода по данным MarketHub
 * @property string|null $source_event_id id события, из которого следует факт
 *
 * @property-read Order $order Заказ, к которому относится запись
 */
class OrderStatusHistory extends Model
{
    /**
     * Имя таблицы задаётся явно: автопредсказание Laravel дало бы
     * `order_status_histories`.
     *
     * @var string
     */
    protected $table = 'order_status_history';

    /**
     * Таблица не имеет created_at/updated_at.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'status',
        'at',
        'source_event_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'at' => 'datetime',
        ];
    }

    /**
     * Заказ, к которому относится запись.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
