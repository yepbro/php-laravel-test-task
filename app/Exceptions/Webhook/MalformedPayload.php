<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

use App\Enums\PayloadIssue;

/**
 * Тело вебхука не соответствует протоколу MarketHub (→ 400 или 422).
 *
 * Конкретная причина — типизированный `PayloadIssue`, по нему
 * HTTP-маппинг (T-010/T-025) выбирает код: ошибки формата — 400
 * (InvalidJson, EventIdMismatch), ошибки структуры — 422
 * (InvalidEnvelope, InvalidSnapshot). В сообщение допустимы путь поля
 * и характер проблемы; тело запроса — нельзя (NFR-04).
 */
final class MalformedPayload extends WebhookException
{
    public function __construct(
        public readonly PayloadIssue $issue,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($issue), $code, $previous);
    }

    private static function defaultMessage(PayloadIssue $issue): string
    {
        return match ($issue) {
            PayloadIssue::InvalidJson => 'Тело вебхука не является корректным JSON UTF-8.',
            PayloadIssue::EventIdMismatch => 'Идентификатор события в заголовке не совпадает с телом.',
            PayloadIssue::InvalidEnvelope => 'Базовый конверт события не прошёл валидацию.',
            PayloadIssue::InvalidSnapshot => 'Снимок заказа не прошёл валидацию.',
        };
    }
}
