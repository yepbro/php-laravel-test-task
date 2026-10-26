<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заказ MarketHub.
 *
 * Ключ записи — идентификатор заказа MarketHub (`ord_…`), поэтому модель
 * не автоинкрементная, а ключ строковый (§6.3 ТЗ).
 *
 * Даты MarketHub (`placed_at`, `source_updated_at`, `paid_at`,
 * `cancelled_at`, `version_event_created_at`) — данные снимка и именно они
 * используются в API (FR-API-07). Системные `created_at`/`updated_at` —
 * технические метки нашей записи и в API не выставляются (§6.3 ТЗ);
 * они не взаимозаменяемы с датами маркетплейса. Все даты — CarbonImmutable
 * (глобально в приложении, FR-ORD-03), хранение — DATETIME в UTC.
 *
 * @property string $id Идентификатор заказа MarketHub
 * @property string $number Номер заказа, который видит покупатель
 * @property OrderStatus $status Статус заказа
 * @property string $currency Код валюты ISO 4217
 * @property string $customer_email Email покупателя как пришёл в снимке
 * @property string $customer_email_normalized Нормализованный email (trim + lower)
 * @property string $customer_name Имя покупателя
 * @property string|null $customer_phone Телефон покупателя
 * @property string $delivery_price Стоимость доставки (строка с двумя знаками)
 * @property string $discount Скидка на заказ (строка с двумя знаками)
 * @property string $total Итог к оплате (строка с двумя знаками)
 * @property string|null $shipment_carrier Служба доставки
 * @property string|null $shipment_tracking_number Трек-номер отправления
 * @property string|null $shipment_tracking_url Ссылка на отслеживание
 * @property CarbonImmutable $placed_at Время оформления заказа (created_at MarketHub)
 * @property CarbonImmutable $source_updated_at Время последнего изменения (updated_at MarketHub)
 * @property CarbonImmutable|null $paid_at Время оплаты
 * @property CarbonImmutable|null $cancelled_at Время отмены
 * @property string|null $cancel_reason Причина отмены
 * @property CarbonImmutable $version_event_created_at created_at события последней применённой версии
 * @property string $version_event_id id события последней применённой версии
 * @property array<string, mixed> $snapshot Последний применённый снимок заказа
 * @property CarbonImmutable|null $created_at Системная метка нашей записи (в API не используется)
 * @property CarbonImmutable|null $updated_at Системная метка нашей записи (в API не используется)
 *
 * @property-read Collection<int, OrderItem> $items Позиции заказа
 * @property-read Collection<int, OrderStatusHistory> $history История статусов
 * @property-read Collection<int, OrderNotification> $notifications Уведомления (outbox)
 */
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /**
     * Идентификатор заказа назначает MarketHub, а не БД.
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Атрибуты, доступные для массового присвоения.
     *
     * `id` входит в список: ключ приходит из снимка вебхука.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'number',
        'status',
        'currency',
        'customer_email',
        'customer_email_normalized',
        'customer_name',
        'customer_phone',
        'delivery_price',
        'discount',
        'total',
        'shipment_carrier',
        'shipment_tracking_number',
        'shipment_tracking_url',
        'placed_at',
        'source_updated_at',
        'paid_at',
        'cancelled_at',
        'cancel_reason',
        'version_event_created_at',
        'version_event_id',
        'snapshot',
    ];

    /**
     * Суммы — `decimal:2`: строка с двумя знаками через BigDecimal, без
     * float (§6.3 ТЗ). Даты MarketHub — `datetime` (Carbon). Системные
     * `created_at`/`updated_at` — стандартные метки Eloquent и отдельного
     * каста не требуют.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'version_event_created_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    /**
     * Позиции заказа (§6.3 ТЗ; при применении снимка заменяются целиком).
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * История статусов: факты переходов по данным MarketHub (§6.4 ТЗ).
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Уведомления покупателя — transactional outbox (§6.5 ТЗ).
     *
     * @return HasMany<OrderNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(OrderNotification::class);
    }
}
