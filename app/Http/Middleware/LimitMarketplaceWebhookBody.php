<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Webhook\PayloadTooLarge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Лимит тела вебхука MarketHub — 1 MiB (§7 шаг 1, FR-WH-12; T-025).
 *
 * В Sail веб-сервер — `php artisan serve`, nginx отсутствует, поэтому
 * лимит проверяет само приложение на самом раннем этапе — до проверки
 * заголовков и подписи (VerifyMarketplaceSignature, шаги 2–4 §7) и до
 * любого JSON-разбора (шаг 5): oversized-запрос отваливается 413 ещё
 * до вычисления HMAC. Превышение → PayloadTooLarge → 413 единым
 * рендером исключений (bootstrap/app.php → WebhookResponseMapper).
 *
 * Проверяются оба источника размера (FR-WH-12):
 *
 * | Проверка | Что прикрывает |
 * |---|---|
 * | заголовок Content-Length > лимита | отказ до чтения тела; случаи, когда настройка PHP молча обрезает фактически переданные байты |
 * | фактический размер сырых байтов `getContent()` | отсутствующий или заниженный Content-Length |
 *
 * Ровно 1 MiB — допустимо: отказ только при строго большем размере.
 * Тело не логируется и не попадает в ответы (NFR-04).
 */
final class LimitMarketplaceWebhookBody
{
    /** Дефолт лимита — тот же, что в config/marketplace.php: исчезновение ключа не должно молча отключать проверку. */
    private const int DEFAULT_MAX_BODY_BYTES = 1024 * 1024;

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('marketplace.webhook_max_body_bytes', self::DEFAULT_MAX_BODY_BYTES);

        // Content-Length — число байтов по RFC; мусорное или отсутствующее
        // значение просто передаёт решение фактическому размеру ниже.
        $contentLength = $request->headers->get('Content-Length');

        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $limit) {
            throw new PayloadTooLarge();
        }

        if (strlen($request->getContent()) > $limit) {
            throw new PayloadTooLarge();
        }

        return $next($request);
    }
}
