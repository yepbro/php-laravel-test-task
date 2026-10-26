<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationType;

/**
 * Ключ дедупликации отправки письма (FR-MAIL-02, A-24/Q24).
 *
 * `paid` и `cancelled` — `{order_id}:{type}`: такие письма заказу нужны
 * ровно один раз. `shipped` — `{order_id}:shipped:{tracking_number}`:
 * повторная отправка с тем же треком не создаётся, с другим — уходит
 * (S-11/S-38). Чистая функция над строками: единственное место формата,
 * используется и outbox-записью (T-032), и фабрикой тестовых данных.
 */
final class NotificationDedupeKey
{
    /**
     * Ключ дедупликации для письма типа $type заказа $orderId.
     *
     * Для `shipped` трек-номер обязателен; NULL допустим только
     * в нестандартной комбинации «письмо есть, shipment нет», которая
     * на реальном потоке закрывается skip-веткой FR-MAIL-08 (no_shipment) —
     * ключ с пустым хвостом не совпадёт ни с одним «настоящим» ключом.
     */
    public static function for(NotificationType $type, string $orderId, ?string $trackingNumber = null): string
    {
        if ($type !== NotificationType::SHIPPED) {
            return "{$orderId}:{$type->value}";
        }

        $trackingNumber ??= '';

        return "{$orderId}:shipped:{$trackingNumber}";
    }
}
