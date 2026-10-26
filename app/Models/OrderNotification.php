<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Уведомление покупателя — запись transactional outbox (§6.5 ТЗ).
 *
 * Создаётся в той же транзакции, что и применение снимка (FR-MAIL-01);
 * отправка — задача очереди (T-012), модель только хранит состояние.
 *
 * @property int $id Идентификатор уведомления
 * @property string $order_id Идентификатор заказа MarketHub
 * @property NotificationType $type Тип письма
 * @property string|null $dedupe_key Ключ дедупликации отправки
 * @property NotificationStatus $status Статус отправки
 * @property string|null $skip_reason Причина пропуска отправки
 * @property string|null $recipient Получатель письма
 * @property string|null $subject Тема письма
 * @property int $attempts Число попыток отправки
 * @property string $source_event_id id события-триггера
 * @property string|null $last_error Последняя ошибка отправки
 * @property CarbonImmutable|null $sent_at Время успешной отправки
 * @property CarbonImmutable|null $created_at Системная метка нашей записи
 * @property CarbonImmutable|null $updated_at Системная метка нашей записи
 *
 * @property-read Order $order Заказ, к которому относится уведомление
 */
class OrderNotification extends Model
{
    /** @use HasFactory<\Database\Factories\OrderNotificationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'type',
        'dedupe_key',
        'status',
        'skip_reason',
        'recipient',
        'subject',
        'attempts',
        'source_event_id',
        'last_error',
        'sent_at',
    ];

    /**
     * `sent_at` — время успешной отправки письма, не системная метка.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Заказ, к которому относится уведомление.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
