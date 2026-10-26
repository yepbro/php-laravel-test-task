<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\PayloadIssue;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\StaleTimestamp;
use App\Exceptions\Webhook\WebhookException;
use Illuminate\Http\JsonResponse;

/**
 * Единый JSON-маппинг HTTP-ответов вебхука (FR-WH-10, скелет T-010).
 *
 * | Источник | Код | Тело |
 * |---|---|---|
 * | WebhookProcessingResult | 200 | `{"status": "processed\|duplicate\|stale\|ignored\|rejected"}` |
 * | InvalidSignature / StaleTimestamp | 401 | единое тело `unauthorized` (NFR-04) |
 * | MalformedPayload: invalid_json, event_id_mismatch | 400 | FR-WH-05 |
 * | MalformedPayload: invalid_envelope, invalid_snapshot | 422 | FR-WH-06/08 |
 *
 * 413 (лимит 1 MiB) и 503 (блокировка/доступность Redis) добавляются
 * задачами T-025/T-027 — структура match'ей расширяема без переделки.
 *
 * Используется и контроллером (успех), и рендером исключений в
 * bootstrap/app.php (ошибки): middleware подписи бросает исключения до
 * контроллера, поэтому точка маппинга одна — здесь.
 *
 * Тексты ошибок — статические константы: getMessage() исключений
 * предназначен для логов и наружу не отдаётся (контракт WebhookException);
 * тела ответов не содержат payload и внутренних деталей (FR-WH-10, NFR-04).
 * Тексты маппера и defaultMessage() исключений — независимые источники
 * по замыслу: сообщения исключений (для логов) обогатятся деталями
 * валидации в T-021, HTTP-тексты останутся стабильными.
 */
final class WebhookResponseMapper
{
    /** Единый код всех причин отказа аутентификации (NFR-04: 401 не раскрывает, какая проверка не прошла). */
    private const string UNAUTHORIZED_CODE = 'unauthorized';

    private const string UNAUTHORIZED_MESSAGE = 'Вебхук не прошёл проверку аутентификации.';

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
     * Ошибки входа — 401 единообразно либо 400/422 по типизированной
     * причине PayloadIssue (FR-WH-05/06/08).
     */
    public static function fromException(WebhookException $exception): JsonResponse
    {
        if ($exception instanceof MalformedPayload) {
            return self::fromPayloadIssue($exception->issue);
        }

        // InvalidSignature и StaleTimestamp — одинаковое тело (NFR-04).
        // Ветка default бросает, а не отдаёт 401 молча: новый подкласс
        // WebhookException (413/503 — T-025/T-027) без собственной ветки
        // уронит тесты, вместо того чтобы незаметно превратить
        // повторяемую ошибку в неповторяемый 401.
        return match (true) {
            $exception instanceof InvalidSignature,
            $exception instanceof StaleTimestamp => self::unauthorized(),
            default => throw new \LogicException(sprintf(
                'Отсутствует ветка маппера для исключения %s — добавьте её (см. T-025/T-027).',
                $exception::class,
            )),
        };
    }

    /**
     * Единое тело 401 для всех причин отказа аутентификации (NFR-04).
     */
    private static function unauthorized(): JsonResponse
    {
        return response()->json(
            ['error' => [
                'code' => self::UNAUTHORIZED_CODE,
                'message' => self::UNAUTHORIZED_MESSAGE,
            ]],
            JsonResponse::HTTP_UNAUTHORIZED,
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

        return response()->json(
            ['error' => [
                'code' => $code,
                'message' => $message,
            ]],
            $status,
        );
    }
}
