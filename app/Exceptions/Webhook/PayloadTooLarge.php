<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Тело вебхука больше лимита 1 MiB (→ 413, FR-WH-12).
 *
 * Бросается middleware LimitMarketplaceWebhookBody — самым ранним шагом
 * приложения (§7 шаг 1): до проверки заголовков/подписи и до любого
 * JSON-разбора. В Sail веб-сервер — php artisan serve (nginx нет),
 * поэтому лимит проверяет само приложение — по Content-Length и
 * фактическому размеру сырых байтов. Ровно 1 MiB — допустимо.
 *
 * Значение лимита — протокольная константа, не секрет: может упоминаться
 * в сообщении; секрет, подпись и тело — нельзя (NFR-04).
 */
final class PayloadTooLarge extends WebhookException
{
    public const string DEFAULT_MESSAGE = 'Тело вебхука превышает допустимый размер.';

    public function __construct(
        string $message = self::DEFAULT_MESSAGE,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
