<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Отметка времени вебхука вне допустимого окна (→ 401, FR-WH-03).
 *
 * `|now − X-Webhook-Timestamp| > MARKETPLACE_WEBHOOK_TOLERANCE`
 * (по умолчанию ±300 с, в обе стороны — в том числе «из будущего»).
 * Допустимое отклонение не секретно и может упоминаться в сообщении
 * для диагностики; конкретное значение timestamp — тоже, но подпись,
 * секрет и тело в сообщение не включать (NFR-04).
 */
final class StaleTimestamp extends WebhookException
{
    public const string DEFAULT_MESSAGE = 'Отметка времени вебхука вне допустимого окна.';

    public function __construct(
        string $message = self::DEFAULT_MESSAGE,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
