<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\MalformedPayload;
use App\Exceptions\Webhook\PayloadTooLarge;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\AssertsNoWebhookSideEffects;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-025. Политика репортинга исключений вебхука: вся иерархия
 * WebhookException — ожидаемые исходы публичного эндпоинта и не
 * репортится обработчиком (ShouldntReport на базовом классе, DECISIONS
 * 010 «Остаток»): клиентские 401/400/422/413 не пишут стектрейсы
 * в логи. Наблюдаемость этих веток — warning-логи NFR-06 (T-026/T-027),
 * не отчёты исключений.
 *
 * Проверяется полный HTTP-путь (Exceptions::fake() в базовом TestCase:
 * report() идёт в фейк, и фейк спрашивает shouldReport у реального
 * обработчика — без ShouldntReport исключение было бы записано
 * и ассерт упал). Здесь по одному представителю каждого источника:
 * middleware (InvalidSignature, PayloadTooLarge) и Action/парсер
 * (MalformedPayload).
 */
class MarketplaceWebhookExceptionReportingTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;
    use AssertsNoWebhookSideEffects;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** Ровно 64 hex-символа — корректная форма, но не подпись этого тела. */
    private const string WRONG_SIGNATURE = 'sha256=a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));
    }

    /** Отказ аутентификации (middleware, 401) — не репортится. */
    public function test_authentication_failure_is_not_reported(): void
    {
        $request = SignedWebhook::forBody('{"id":"evt_reporting"}')
            ->withHeader('X-Webhook-Signature', self::WRONG_SIGNATURE);

        $this->postSignedWebhook($request)->assertUnauthorized();

        Exceptions::assertNotReported(InvalidSignature::class);
        $this->assertNoWebhookSideEffects();
    }

    /** Невалидное тело (Action/парсер, 400) — не репортится. */
    public function test_malformed_payload_is_not_reported(): void
    {
        $request = SignedWebhook::forBody('{"id":"evt_reporting", не json' . "\n")
            ->withHeader('X-Webhook-Id', 'evt_reporting');

        $this->postSignedWebhook($request)->assertBadRequest();

        Exceptions::assertNotReported(MalformedPayload::class);
        $this->assertNoWebhookSideEffects();
    }

    /** Oversized-тело (middleware лимита, 413) — не репортится. */
    public function test_oversized_payload_is_not_reported(): void
    {
        $request = SignedWebhook::forBody(str_repeat('x', 1024 * 1024 + 1))
            ->withHeader('X-Webhook-Id', 'evt_reporting_big');

        $this->postSignedWebhook($request)->assertStatus(413);

        Exceptions::assertNotReported(PayloadTooLarge::class);
        $this->assertNoWebhookSideEffects();
    }
}
