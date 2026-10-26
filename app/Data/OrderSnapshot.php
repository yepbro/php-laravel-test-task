<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Полный снимок заказа MarketHub из `data.order` (T-009).
 *
 * Все поля — из task/marketplace-webhooks.md. DTO представляет снимок,
 * прошедший валидацию известного статуса (FR-WH-08): поэтому `status` —
 * enum, а не строка; события с неизвестным строковым статусом (FR-WH-07,
 * `ignored`) до полной валидации снимка не доходят и это DTO не строят.
 *
 * Денежные суммы (`price`, `deliveryPrice`, `discount`, `total`) —
 * строки с двумя знаками, float запрещён (NFR-02); `total` хранится
 * дословно как прислал маркетплейс — расхождение с арифметикой позиций
 * не повод для отказа (FR-WH-09, S-27).
 *
 * Nullable-поля (`shipment`, `paidAt`, `cancelledAt`, `cancelReason`,
 * `customer.phone`) — explicit null semantics: MarketHub передаёт их
 * явно, в том числе «сбрасывает» в null свежим снимком (T6: снимок
 * полный, перезапись целиком — FR-ORD-04).
 *
 * Даты — моменты времени с исходным смещением (примеры содержат +03:00
 * и +00:00); сравнивать только как моменты (FR-ORD-03), нормализация
 * в UTC — при записи в БД/сравнении версий.
 */
final readonly class OrderSnapshot
{
    /**
     * @param list<OrderItemSnapshot> $items позиции в порядке снимка (FR-ORD-04)
     */
    public function __construct(
        public string $id,
        public string $number,
        public OrderStatus $status,
        public string $currency,
        public OrderCustomer $customer,
        public array $items,
        public string $deliveryPrice,
        public string $discount,
        public string $total,
        public ?OrderShipment $shipment,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $cancelledAt,
        public ?string $cancelReason,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}
}
