<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал обработки событий вебхуков MarketHub.
 *
 * Ключ записи — `event.id` из конверта (§6.3 ТЗ). Событие вставляется
 * до создания/обновления заказа, поэтому `order_id` — мягкая ссылка без
 * внешнего ключа и может быть NULL (в т.ч. у событий неизвестных типов).
 *
 * @property string $id Идентификатор события (= event.id)
 * @property string $type Тип события (order.created, …)
 * @property string|null $order_id Идентификатор заказа из снимка (без FK)
 * @property int $signature_timestamp Unix time из заголовка X-Webhook-Timestamp
 * @property string $payload Сырое тело события (UTF-8, байт в байт)
 * @property string $payload_sha256 SHA-256 хеш тела для диагностики повторов
 * @property EventOutcome $outcome Исход обработки
 * @property string|null $reject_reason Причина отказа (например, terminal_status)
 * @property int $deliveries Количество доставок события
 * @property CarbonImmutable $received_at Время первой приёмки
 * @property CarbonImmutable|null $last_received_at Время последней повторной доставки
 * @property CarbonImmutable|null $processed_at Время завершения обработки
 *
 * @property-read Order|null $order Заказ из снимка, если существует
 */
class WebhookEvent extends Model
{
    /**
     * Идентификатор события назначает MarketHub, а не БД.
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Таблица не имеет created_at/updated_at: собственные метки журнала —
     * `received_at`/`last_received_at`/`processed_at`.
     */
    public $timestamps = false;

    /**
     * `id` входит в список: ключ приходит из конверта события.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'type',
        'order_id',
        'signature_timestamp',
        'payload',
        'payload_sha256',
        'outcome',
        'reject_reason',
        'deliveries',
        'received_at',
        'last_received_at',
        'processed_at',
    ];

    /**
     * `payload` намеренно без каста: сырые байты тела хранятся и читаются
     * байт в байт (FR-ORD-08), JSON-разбор — задача обработчика, не модели.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => EventOutcome::class,
            'signature_timestamp' => 'integer',
            'deliveries' => 'integer',
            'received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Заказ из снимка события. Связь мягкая (без FK): на момент вставки
     * события заказа может ещё не быть, отсюда NULL.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
