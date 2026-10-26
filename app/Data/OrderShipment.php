<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Данные отправки из снимка заказа MarketHub (T-009).
 *
 * Поля — из task/marketplace-webhooks.md (`shipment.{carrier,
 * tracking_number,tracking_url}`). Объект существует только когда
 * маркетплейс передал непустой `shipment`; «отправки нет» — это
 * `null` в OrderSnapshot::$shipment (explicit null semantics), а не
 * пустые поля внутри. Трек-номер входит в dedupe_key письма shipped
 * (FR-MAIL-02, задача T-033).
 */
final readonly class OrderShipment
{
    public function __construct(
        public string $carrier,
        public string $trackingNumber,
        public string $trackingUrl,
    ) {}
}
