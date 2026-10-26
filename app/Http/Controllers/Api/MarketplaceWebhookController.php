<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Webhooks\ProcessMarketplaceEvent;
use App\Data\IncomingWebhook;
use App\Http\Controllers\Controller;
use App\Http\Responses\WebhookResponseMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Приём вебхука MarketHub: POST /api/webhooks/marketplace (§6.1, T-010).
 *
 * Тонкий HTTP-слой без бизнес-логики (NFR-08): подпись и окно timestamp
 * уже проверены middleware VerifyMarketplaceSignature; контроллер лишь
 * извлекает контекст доставки из запроса, передаёт его в Action и
 * преобразует типизированный результат в JSON (WebhookResponseMapper).
 * Ошибки (MalformedPayload из Action) сюда не попадают — их рендерит
 * единый обработчик в bootstrap/app.php.
 */
final class MarketplaceWebhookController extends Controller
{
    public function __construct(
        private readonly ProcessMarketplaceEvent $processMarketplaceEvent,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->processMarketplaceEvent->handle($this->incomingWebhook($request));

        return WebhookResponseMapper::fromResult($result);
    }

    /**
     * Контекст доставки для Action: заголовки и сырое тело байт в байт
     * (T1 — без нормализации). Формат и окно гарантированы middleware,
     * поэтому приведение timestamp к int здесь безопасно.
     */
    private function incomingWebhook(Request $request): IncomingWebhook
    {
        $timestamp = $request->header('X-Webhook-Timestamp');

        return new IncomingWebhook(
            webhookId: $this->stringHeader($request, 'X-Webhook-Id'),
            signatureTimestamp: is_string($timestamp) ? (int) $timestamp : 0,
            rawBody: $request->getContent(),
        );
    }

    private function stringHeader(Request $request, string $name): string
    {
        $value = $request->header($name);

        return is_string($value) ? $value : '';
    }
}
