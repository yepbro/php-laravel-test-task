<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статус отправки уведомления (transactional outbox).
 *
 * Строковые значения совпадают с колонкой `order_notifications.status`
 * (VARCHAR(16)) и статусами из §6.5 ТЗ plan/TZ-markethub-orders-service-v1.4.md.
 * Допустимые переходы статуса (FR-MAIL-04/FR-MAIL-05: `pending → sending →
 * sent`, `pending/sending → skipped/failed` с ретраями и backoff)
 * реализуются в заданиях отправки (T-012) и здесь не кодируются.
 */
enum NotificationStatus: string
{
    /** Запись создана в outbox, ждёт постановки/отправки (FR-MAIL-01). */
    case PENDING = 'pending';

    /** Захвачено воркером перед отправкой (FR-MAIL-04). */
    case SENDING = 'sending';

    /** Письмо отправлено. */
    case SENT = 'sent';

    /** Отправка пропущена по правилам допустимости (FR-MAIL-03/07/08). */
    case SKIPPED = 'skipped';

    /** Попытки исчерпаны (FR-MAIL-05, backoff 10с/1м/5м/15м, максимум 5). */
    case FAILED = 'failed';
}
