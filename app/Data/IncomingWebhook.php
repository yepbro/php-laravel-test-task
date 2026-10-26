<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Контекст доставки вебхука, передаваемый в Action обработки (T-010).
 *
 * Изолирует транзакционную логику (ProcessMarketplaceEvent) от HTTP-слоя:
 * Action не принимает Request, а получает уже извлечённые контроллером
 * primitives. Поля — то, что нужно шагам §7.5–§7.13: совпадение
 * `X-Webhook-Id` с `body.id` (FR-WH-05), `signature_timestamp` колонки
 * `webhook_events` (§6.3) и сырые байты тела байт в байт (T1: подпись
 * и журнал используют тело ровно как пришло, без нормализации).
 *
 * Формат заголовков и окно timestamp уже проверены middleware
 * VerifyMarketplaceSignature до попадания сюда.
 */
final readonly class IncomingWebhook
{
    public function __construct(
        public string $webhookId,
        public int $signatureTimestamp,
        public string $rawBody,
    ) {}
}
