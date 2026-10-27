<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Обработка вебхука временно недоступна (→ 503, FR-WH-10/FR-ORD-07).
 *
 * Повторяемый отказ: MarketHub обязан повторить доставку позже. Причины:
 * не получена блокировка заказа `order:{id}` в пределах order_lock_timeout
 * (§7 шаг 12) либо недоступны Redis/БД.
 *
 * Контракт HTTP-ответа (503 + машинный код) закреплён задачей T-025
 * вместе с веткой WebhookResponseMapper; бросающие места появляются
 * в T-027 (блокировки и транзакционный обработчик). Наблюдаемость
 * повтора — warning-лог NFR-06 (T-027), не стектрейс исключения:
 * класс наследует ShouldntReport у WebhookException.
 */
final class WebhookUnavailable extends WebhookException
{
    public const string DEFAULT_MESSAGE = 'Обработка вебхука временно недоступна, повторите доставку позже.';

    public function __construct(
        string $message = self::DEFAULT_MESSAGE,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
