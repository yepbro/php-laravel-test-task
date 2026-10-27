<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-скелет вебхука (T-010): маршрут POST /api/webhooks/marketplace
 * зарегистрирован, api-группа не мешает (CSRF/throttle отсутствуют,
 * ответ JSON даже с Accept: text/html — FR-WH-11/FR-API-09). Подпись
 * сверяется (T-024): ошибки аутентификации — единый 401, формально
 * корректные заголовки с неверным HMAC тоже отклоняются 401 (fail
 * closed, фиктивного `200 processed` нет).
 */
class MarketplaceWebhookRouteTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** Ровно 64 hex-символа — корректная форма X-Webhook-Signature (FR-WH-02). */
    private const string HEX64 = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private const string VALID_SIGNATURE = 'sha256=' . self::HEX64;

    private const string RAW_BODY = '{"id":"evt_test","type":"order.paid"}' . "\n";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);
    }

    public function test_route_is_registered_and_answers(): void
    {
        // Без заголовков вебхука: маршрут существует и обрабатывается
        // middleware подписи — 401 (не 404/405), а не успешная обработка.
        $response = $this->call('POST', '/api/webhooks/marketplace', [], [], [], ['CONTENT_TYPE' => 'application/json'], self::RAW_BODY);

        $response->assertUnauthorized();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(
            ['error' => ['code' => 'unauthorized', 'message' => 'Вебхук не прошёл проверку аутентификации.']],
            $response->json(),
        );
    }

    /**
     * Ответы api/* — JSON независимо от Accept (FR-WH-11/FR-API-09).
     */
    public function test_rejects_html_accept_with_json_body(): void
    {
        $server = array_merge($this->server(), ['HTTP_ACCEPT' => 'text/html']);
        unset($server['HTTP_X_WEBHOOK_ID'], $server['HTTP_X_WEBHOOK_TIMESTAMP'], $server['HTTP_X_WEBHOOK_SIGNATURE']);

        $response = $this->call('POST', '/api/webhooks/marketplace', [], [], [], $server, self::RAW_BODY);

        $response->assertUnauthorized();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('unauthorized', $response->json('error.code'));
    }

    public function test_stale_timestamp_shares_unified_401_body(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $staleSeconds = CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp() - 301;
        $server = $this->server(['X-Webhook-Timestamp' => (string) $staleSeconds]);

        $response = $this->call('POST', '/api/webhooks/marketplace', [], [], [], $server, self::RAW_BODY);

        $response->assertUnauthorized();
        $this->assertSame(
            ['error' => ['code' => 'unauthorized', 'message' => 'Вебхук не прошёл проверку аутентификации.']],
            $response->json(),
        );
    }

    public function test_empty_secret_fails_closed(): void
    {
        config(['marketplace.webhook_secret' => '']);
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $response = $this->call('POST', '/api/webhooks/marketplace', [], [], [], $this->server(), self::RAW_BODY);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
    }

    /**
     * Формально корректные заголовки с НЕВАЛИДНЫМ HMAC (подпись не этого
     * тела) → единый 401 (T-024): до контроллера доходят только
     * подписанные запросы, фиктивного `200 processed` нет. Перевёрнут
     * с ожидания 500 от LogicException-заглушки сверки (T-024 снята).
     */
    public function test_valid_form_headers_with_wrong_signature_get_401(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $response = $this->call('POST', '/api/webhooks/marketplace', [], [], [], $this->server(), self::RAW_BODY);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
        $this->assertDatabaseCount('webhook_events', 0);
    }

    /**
     * Server-массив для call(): CONTENT_TYPE + HTTP_* ключи заголовков
     * доставки (дефолты + явные override'ы заголовков по их именам).
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function server(array $overrides = []): array
    {
        $headers = array_merge([
            'X-Webhook-Id' => 'evt_test',
            'X-Webhook-Timestamp' => (string) CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp(),
            'X-Webhook-Signature' => self::VALID_SIGNATURE,
        ], $overrides);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
