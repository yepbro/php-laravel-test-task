<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тип письма покупателю.
 *
 * Строковые значения совпадают с колонкой `order_notifications.type`
 * (VARCHAR(16)) и типами писем из §6.5 ТЗ plan/TZ-markethub-orders-service-v1.4.md.
 * Письмо создаётся только по применённому снимку, который меняет статус
 * заказа на `paid`, `shipped` или `cancelled` (§6.5, A-22); допустимость
 * отправки по текущему статусу проверяется отдельно (матрица §6.5).
 */
enum NotificationType: string
{
    /** Заказ оплачен. */
    case PAID = 'paid';

    /** Заказ передан в доставку. */
    case SHIPPED = 'shipped';

    /** Заказ отменён. */
    case CANCELLED = 'cancelled';
}
