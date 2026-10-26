<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Подпись вебхука MarketHub для тестов (T-015) — дословно по
 * `task/examples/send-event.php` и §3.1/FR-WH-04 ТЗ:
 *
 *     hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret)
 *
 * Подписывается СЫРОЕ тело (байт в байт, включая финальный `\n`),
 * не нормализованный JSON. Результат — 64 hex-символа в нижнем
 * регистре (такой вывод даёт hash_hmac); заголовок — `sha256=<hex>`.
 *
 * Хелпер существует отдельно от будущего App\Support\MarketplaceSignature
 * (T-024) сознательно: тесты обязаны подписывать независимо от
 * реализации приложения — эталон signature-example.md самопроверяется
 * здесь и не зависит от кода продакшена.
 */
final class WebhookSignature
{
    /** Значение X-Webhook-Signature: `sha256=` + hex (нижний регистр). */
    public const string HEADER_PREFIX = 'sha256=';

    /**
     * HMAC-SHA256 от `"{timestamp}.{сырое тело}"` — hex в нижнем
     * регистре (формат FR-WH-02).
     */
    public static function sign(string $rawBody, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Готовое значение заголовка X-Webhook-Signature.
     */
    public static function header(string $rawBody, int $timestamp, string $secret): string
    {
        return self::HEADER_PREFIX . self::sign($rawBody, $timestamp, $secret);
    }
}
