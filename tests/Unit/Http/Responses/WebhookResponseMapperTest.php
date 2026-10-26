<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Responses;

use App\Enums\PayloadIssue;
use App\Enums\WebhookProcessingResult;
use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\StaleTimestamp;
use App\Exceptions\Webhook\WebhookException;
use App\Http\Responses\WebhookResponseMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WebhookResponseMapper (T-010): типизированный результат → HTTP 200,
 * исключения T-009 → 401 (единое тело, NFR-04) и 400/422 по PayloadIssue.
 * Тексты ошибок статичны — getMessage() исключений наружу не отдаётся.
 */
class WebhookResponseMapperTest extends TestCase
{
    public function test_from_result_returns_200_with_status_field(): void
    {
        foreach (WebhookProcessingResult::cases() as $result) {
            $response = WebhookResponseMapper::fromResult($result);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['status' => $result->value], $response->getData(true));
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
        }
    }

    public function test_authentication_failures_share_single_401_body(): void
    {
        $signatureResponse = WebhookResponseMapper::fromException(new InvalidSignature());
        $timestampResponse = WebhookResponseMapper::fromException(new StaleTimestamp());

        $expectedBody = [
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Вебхук не прошёл проверку аутентификации.',
            ],
        ];

        $this->assertSame(401, $signatureResponse->getStatusCode());
        $this->assertSame(401, $timestampResponse->getStatusCode());
        $this->assertSame($expectedBody, $signatureResponse->getData(true));
        $this->assertSame($signatureResponse->getContent(), $timestampResponse->getContent());
    }

    /**
     * @return array<string, array{PayloadIssue, int, string}>
     */
    public static function payloadIssues(): array
    {
        return [
            'invalid json → 400' => [PayloadIssue::InvalidJson, 400, 'invalid_json'],
            'event id mismatch → 400' => [PayloadIssue::EventIdMismatch, 400, 'event_id_mismatch'],
            'invalid envelope → 422' => [PayloadIssue::InvalidEnvelope, 422, 'invalid_envelope'],
            'invalid snapshot → 422' => [PayloadIssue::InvalidSnapshot, 422, 'invalid_snapshot'],
        ];
    }

    #[DataProvider('payloadIssues')]
    public function test_payload_issues_map_to_400_and_422(PayloadIssue $issue, int $status, string $code): void
    {
        $response = WebhookResponseMapper::fromException(new MalformedPayload($issue));

        $body = $response->getData(true);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($code, $body['error']['code'] ?? '');
        $this->assertNotSame('', $body['error']['message'] ?? '');
    }

    /**
     * Тело ответа строится по коду PayloadIssue, а не по сообщению
     * исключения: детали валидации (там будут пути полей от парсера
     * T-021) — для логов, наружу — статичный текст (контракт
     * WebhookException, FR-WH-10).
     */
    public function test_exception_message_is_not_exposed(): void
    {
        $response = WebhookResponseMapper::fromException(
            new MalformedPayload(PayloadIssue::InvalidEnvelope, 'Поле data.order обязательно.'),
        );

        $this->assertSame('Базовый конверт события не прошёл валидацию.', $response->getData(true)['error']['message']);
    }

    /**
     * Будущий подкласс WebhookException без собственной ветки маппера
     * (413/503 добавят T-025/T-027) рушится громко, а не получает молча
     * неповторяемый 401.
     */
    public function test_unknown_webhook_exception_fails_loudly_instead_of_silent_401(): void
    {
        $unknown = new class extends WebhookException {};

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Отсутствует ветка маппера');

        WebhookResponseMapper::fromException($unknown);
    }
}
