<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Базовый класс ошибок входящего вебхука MarketHub (T-009).
 *
 * Иерархия по §10 ТЗ:
 *
 * | Исключение         | HTTP | Требование |
 * |--------------------|------|-------------|
 * | InvalidSignature   | 401  | FR-WH-02/FR-WH-04 |
 * | StaleTimestamp     | 401  | FR-WH-03 |
 * | MalformedPayload   | 400/422 | FR-WH-05/FR-WH-06/FR-WH-08 |
 * | PayloadTooLarge    | 413  | FR-WH-12 |
 * | WebhookUnavailable | 503  | FR-WH-10/FR-ORD-07 (бросает ProcessMarketplaceEvent, T-027) |
 *
 * Преобразование в HTTP-ответы — WebhookResponseMapper (T-010/T-025).
 * Контракт для них: тело HTTP-ответа 401 — ЕДИНОЕ для всех причин отказа
 * аутентификации (NFR-04), getMessage() исключения предназначен только
 * для логов, наружу его не отдавать. Безопасность (NFR-04): в сообщения
 * и контекст запрещено включать секрет, подпись и тело запроса.
 * Идентификаторы события/заказа включать можно (NFR-06).
 *
 * Все WebhookException — ожидаемые (не аварийные) исходы публичного
 * эндпоинта, поэтому вся иерархия исключена из репортинга обработчиком
 * (ShouldntReport, T-025): клиентские 401/400/422/413 и повторяемый 503
 * не пишут стектрейсы в логи. Наблюдаемость этих веток — структурированные
 * warning-логи NFR-06 (задачи T-026/T-027), а не отчёты исключений.
 */
abstract class WebhookException extends RuntimeException implements ShouldntReport {}
