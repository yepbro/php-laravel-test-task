<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Подпись вебхука не прошла проверку (→ 401, FR-WH-02/FR-WH-04).
 *
 * Единые причины: отсутствуют или неверно отформатированы обязательные
 * заголовки; HMAC от `"{timestamp}.{сырое тело}"` не совпал (hash_equals).
 * Сообщение по умолчанию обобщённое в рамках класса и не раскрывает,
 * какая именно проверка не прошла; наружу тело 401-ответа едино для
 * всех причин (см. контракт в WebhookException, NFR-04): подпись,
 * секрет и тело в сообщение не включать.
 */
final class InvalidSignature extends WebhookException
{
    public const string DEFAULT_MESSAGE = 'Вебхук не прошёл проверку подписи.';

    public function __construct(
        string $message = self::DEFAULT_MESSAGE,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
