<?php

declare(strict_types=1);

namespace App\Exceptions\Webhook;

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
 *
 * Преобразование в HTTP-ответы — задача T-010/T-025. Контракт для них:
 * тело HTTP-ответа 401 — ЕДИНОЕ для всех причин отказа аутентификации
 * (NFR-04), getMessage() исключения предназначен только для логов,
 * наружу его не отдавать. Безопасность (NFR-04): в сообщения и контекст
 * запрещено включать секрет, подпись и тело запроса. Идентификаторы
 * события/заказа включать можно (NFR-06).
 */
abstract class WebhookException extends RuntimeException {}
