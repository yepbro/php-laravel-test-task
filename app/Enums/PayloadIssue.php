<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Причина отбраковки тела вебхука (T-009).
 *
 * Дискриминатор для MalformedPayload: HTTP-маппинг (задача T-025)
 * различает ошибки формата (400, FR-WH-05) и ошибки структуры
 * (422, FR-WH-06/FR-WH-08) по этому enum, не разбирая текст сообщения.
 *
 * Кодовый enum без привязки к БД (в отличие от OrderStatus и др.):
 * в журнале webhook_events невалидные тела не сохраняются (FR-ORD-08),
 * значения используются только в коде и логах.
 */
enum PayloadIssue: string
{
    /** Тело — не корректный JSON UTF-8 (→ 400, FR-WH-05). */
    case InvalidJson = 'invalid_json';

    /** X-Webhook-Id не совпадает с `id` тела (→ 400, FR-WH-05). */
    case EventIdMismatch = 'event_id_mismatch';

    /** Базовый конверт события не прошёл валидацию (→ 422, FR-WH-06). */
    case InvalidEnvelope = 'invalid_envelope';

    /** Снимок заказа известного статуса не прошёл валидацию (→ 422, FR-WH-08). */
    case InvalidSnapshot = 'invalid_snapshot';
}
