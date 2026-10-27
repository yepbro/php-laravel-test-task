<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

/**
 * Обработка вебхука временно недоступна (→ 503, FR-WH-10/FR-ORD-07).
 *
 * Повторяемый отказ: MarketHub обязан повторить доставку позже. Причины
 * (бросает ProcessMarketplaceEvent, T-027): не получена блокировка
 * заказа `order:{id}` в пределах order_lock_timeout (§7 шаг 12) либо
 * исчерпаны попытки транзакции применения при конкурентной ошибке БД
 * (deadlock/lock wait timeout, FR-ORD-07).
 *
 * Контракт HTTP-ответа (503 + машинный код) — WebhookResponseMapper
 * (T-025). Заголовок Retry-After не отдаётся: ТЗ его не требует,
 * политика повтора — за MarketHub (DECISIONS, задача 027).
 * Наблюдаемость повтора — warning-лог NFR-06 (`order_lock_timeout`,
 * `transaction_retry_exhausted`), не стектрейс исключения:
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
