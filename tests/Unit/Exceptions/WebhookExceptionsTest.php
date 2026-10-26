<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Enums\PayloadIssue;
use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\StaleTimestamp;
use App\Exceptions\Webhook\WebhookException;
use PHPUnit\Framework\TestCase;

/**
 * Семейство исключений вебхука (T-009): иерархия по §10 ТЗ, типизированный
 * дискриминатор MalformedPayload и безопасность сообщений (NFR-04: без
 * секрета, подписи и тела запроса).
 */
class WebhookExceptionsTest extends TestCase
{
    public function test_all_belong_to_webhook_family(): void
    {
        $this->assertInstanceOf(WebhookException::class, new InvalidSignature());
        $this->assertInstanceOf(WebhookException::class, new StaleTimestamp());
        $this->assertInstanceOf(
            WebhookException::class,
            new MalformedPayload(PayloadIssue::InvalidJson),
        );
    }

    public function test_signature_and_timestamp_carry_generic_messages(): void
    {
        // 401 одинаков для всех причин отказа аутентификации (NFR-04):
        // сообщение не детализирует, какая именно проверка не прошла.
        $this->assertSame('Вебхук не прошёл проверку подписи.', (new InvalidSignature())->getMessage());
        $this->assertSame(
            'Отметка времени вебхука вне допустимого окна.',
            (new StaleTimestamp())->getMessage(),
        );
    }

    public function test_malformed_payload_exposes_typed_issue(): void
    {
        $exception = new MalformedPayload(PayloadIssue::EventIdMismatch);

        $this->assertSame(PayloadIssue::EventIdMismatch, $exception->issue);
        $this->assertSame(
            'Идентификатор события в заголовке не совпадает с телом.',
            $exception->getMessage(),
        );
    }

    public function test_malformed_payload_defaults_cover_all_issues(): void
    {
        $messages = [
            PayloadIssue::InvalidJson,
            PayloadIssue::EventIdMismatch,
            PayloadIssue::InvalidEnvelope,
            PayloadIssue::InvalidSnapshot,
        ];

        foreach ($messages as $issue) {
            $message = (new MalformedPayload($issue))->getMessage();

            $this->assertNotSame('', $message);
        }
    }

    /**
     * Сообщение по умолчанию не содержит ни секрета, ни подписи, ни тела
     * запроса; кастомное сообщение (детали валидации от парсера T-021)
     * передаётся явно и не подменяется.
     */
    public function test_messages_never_embed_body_or_secret_and_custom_message_wins(): void
    {
        $default = (new MalformedPayload(PayloadIssue::InvalidEnvelope))->getMessage();

        $this->assertStringNotContainsString('whsec_', $default);
        $this->assertStringNotContainsString('sha256=', $default);

        $custom = new MalformedPayload(PayloadIssue::InvalidSnapshot, 'Поле items обязательно.');

        $this->assertSame('Поле items обязательно.', $custom->getMessage());
    }
}
