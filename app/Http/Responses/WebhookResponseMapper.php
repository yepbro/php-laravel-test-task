<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\PayloadIssue;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\PayloadTooLarge;
use App\Exceptions\Webhook\StaleTimestamp;
use App\Exceptions\Webhook\WebhookException;
use App\Exceptions\Webhook\WebhookUnavailable;
use Illuminate\Http\JsonResponse;

/**
 * Единый JSON-маппинг HTTP-ответов вебхука (FR-WH-10; скелет T-010,
 * полная таблица кодов — T-025).
 *
 * | Источник | Код | Тело |
 * |---|---|---|
 * | WebhookProcessingResult | 200 | `{"status": "processed\|duplicate\|stale\|ignored\|rejected"}` |
 * | InvalidSignature / StaleTimestamp | 401 | единое тело `unauthorized` (NFR-04) |
 * | PayloadTooLarge | 413 | `payload_too_large` (FR-WH-12) |
 * | MalformedPayload: invalid_json, event_id_mismatch | 400 | FR-WH-05 |
 * | MalformedPayload: invalid_envelope, invalid_snapshot | 422 | FR-WH-06/08 |
 * | WebhookUnavailable | 503 | `temporarily_unavailable` — повторяемый отказ (FR-WH-10/FR-ORD-07; бросает ProcessMarketplaceEvent — T-027, ветка закреплена T-025) |
 *
 * Используется и контроллером (успех), и рендером исключений в
 * bootstrap/app.php (ошибки): middleware подписи и лимита тела бросают
 * исключения до контроллера, поэтому точка маппинга одна — здесь.
 *
 * Тексты ошибок — статические константы: getMessage() исключений
 * предназначен для логов и наружу не отдаётся (контракт WebhookException);
 * тела ответов не содержат payload и внутренних деталей (FR-WH-10, NFR-04).
 * Тексты маппера и defaultMessage() исключений — независимые источники
 * по замыслу: сообщения исключений (для логов) обогащаются деталями
 * валидации, HTTP-тексты остаются стабильными.
 */
final class WebhookResponseMapper
{
    /** Единый код всех причин отказа аутентификации (NFR-04: 401 не раскрывает, какая проверка не прошла). */
    private const string UNAUTHORIZED_CODE = 'unauthorized';

    private const string UNAUTHORIZED_MESSAGE = 'Вебхук не прошёл проверку аутентификации.';

    private const string PAYLOAD_TOO_LARGE_CODE = 'payload_too_large';

    private const string PAYLOAD_TOO_LARGE_MESSAGE = 'Тело вебхука превышает допустимый размер.';

    private const string TEMPORARILY_UNAVAILABLE_CODE = 'temporarily_unavailable';

    private const string TEMPORARILY_UNAVAILABLE_MESSAGE = 'Обработка вебхука временно недоступна, повторите доставку позже.';

    /**
     * Успешная обработка — 200 с полем `status` (FR-WH-10).
     */
    public static function fromResult(WebhookProcessingResult $result): JsonResponse
    {
        return response()->json(
            ['status' => $result->value],
            JsonResponse::HTTP_OK,
        );
    }

    /**
     * Ошибки входа — 401 единообразно, 413 лимит тела, 503 повторяемая
     * недоступность, 400/422 по типизированной причине PayloadIssue
     * (FR-WH-05/06/08/12, FR-WH-10).
     */
    public static function fromException(WebhookException $exception): JsonResponse
    {
        if ($exception instanceof MalformedPayload) {
            return self::fromPayloadIssue($exception->issue);
        }

        // InvalidSignature и StaleTimestamp — одинаковое тело (NFR-04).
        // Ветка default бросает, а не отдаёт 401 молча: новый подкласс
        // WebhookException без собственной ветки уронит тесты, вместо
        // того чтобы незаметно превратить повторяемую ошибку
        // в неповторяемый 401.
        return match (true) {
            $exception instanceof InvalidSignature,
            $exception instanceof StaleTimestamp => self::unauthorized(),
            $exception instanceof PayloadTooLarge => self::error(
                JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE,
                self::PAYLOAD_TOO_LARGE_CODE,
                self::PAYLOAD_TOO_LARGE_MESSAGE,
            ),
            $exception instanceof WebhookUnavailable => self::error(
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
                self::TEMPORARILY_UNAVAILABLE_CODE,
                self::TEMPORARILY_UNAVAILABLE_MESSAGE,
            ),
            default => throw new \LogicException(sprintf(
                'Отсутствует ветка маппера для исключения %s — добавьте её.',
                $exception::class,
            )),
        };
    }

    /**
     * Единое тело 401 для всех причин отказа аутентификации (NFR-04).
     */
    private static function unauthorized(): JsonResponse
    {
        return self::error(JsonResponse::HTTP_UNAUTHORIZED, self::UNAUTHORIZED_CODE, self::UNAUTHORIZED_MESSAGE);
    }

    /**
     * Тело ошибки контракта вебхука: `{"error":{"code","message"}}`
     * (FR-WH-10: JSON, без payload и внутренних деталей).
     */
    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(
            ['error' => [
                'code' => $code,
                'message' => $message,
            ]],
            $status,
        );
    }

    /**
     * @return array{int, string, string} [HTTP-код, машинный код, статичное сообщение]
     */
    private static function payloadIssueParams(PayloadIssue $issue): array
    {
        return match ($issue) {
            PayloadIssue::InvalidJson => [
                JsonResponse::HTTP_BAD_REQUEST,
                'invalid_json',
                'Тело вебхука не является корректным JSON UTF-8.',
            ],
            PayloadIssue::EventIdMismatch => [
                JsonResponse::HTTP_BAD_REQUEST,
                'event_id_mismatch',
                'Идентификатор события в заголовке не совпадает с телом.',
            ],
            PayloadIssue::InvalidEnvelope => [
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_envelope',
                'Базовый конверт события не прошёл валидацию.',
            ],
            PayloadIssue::InvalidSnapshot => [
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_snapshot',
                'Снимок заказа не прошёл валидацию.',
            ],
        };
    }

    private static function fromPayloadIssue(PayloadIssue $issue): JsonResponse
    {
        [$status, $code, $message] = self::payloadIssueParams($issue);

        return self::error($status, $code, $message);
    }
}
