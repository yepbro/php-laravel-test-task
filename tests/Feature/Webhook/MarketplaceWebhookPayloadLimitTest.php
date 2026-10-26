<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoWebhookSideEffects;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт лимита тела вебхука: 1 MiB → 413 (FR-WH-10/12).
 *
 * Лимит проверяется приложением на раннем этапе (§7 шаг 1 — до разбора
 * JSON; в Sail нет nginx, FR-WH-12) — поэтому oversized-мусор, не
 * являющийся JSON, тоже обязан получать 413, а не 400: тело до проверки
 * лимита не парсится.
 *
 * Красная фаза: лимит не реализован (T-025); подписанные oversized-запросы
 * сейчас упираются в заглушку HMAC (T-024, 500) и тесты падают на
 * утверждении «413». Тело 413 заранее не пиннится дословно (форма за
 * T-025), фиксируются статус, JSON-тип и отсутствие побочных эффектов.
 *
 * Большое тело генерируется str_repeat внутри теста (не в датапровайдере):
 * ~1 MiB строка не должна аллоцироваться на каждый запуск сьюта.
 * Граница «ровно 1 MiB допустимо» на HTTP-уровне не пиннится — её
 * зафиксирует unit-тест T-025 на самой проверке.
 */
class MarketplaceWebhookPayloadLimitTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;
    use AssertsNoWebhookSideEffects;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** 1 MiB (FR-WH-12). */
    private const int ONE_MIB = 1024 * 1024;

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

    /**
     * Валидный подписанный JSON больше 1 MiB → 413 до любого разбора;
     * JSON-оформление ответа (без payload и внутренних деталей, FR-WH-10)
     * и никаких записей в БД.
     */
    public function test_oversized_valid_json_returns_413(): void
    {
        $body = '{"id":"evt_big","type":"order.paid","created_at":"2026-10-27T12:00:00+00:00"'
            . ',"data":{"padding":"' . str_repeat('x', self::ONE_MIB) . '"}}';

        $this->assertGreaterThan(self::ONE_MIB, strlen($body), 'Тело должно строго превышать 1 MiB.');

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(413, $response->getStatusCode(), 'Тело больше 1 MiB обязано отклоняться как 413.');
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertNotSame('', (string) $response->json('error.code'), 'Тело 413 — JSON с машинным кодом ошибки.');
        $this->assertNoWebhookSideEffects();
    }

    /**
     * Oversized-тело, не являющееся JSON: 413 должен прийти ДО попытки
     * разбора (§7 шаг 1 раньше шага 5) — иначе такой запрос ответил бы
     * 400 invalid_json. Заголовки заданы явной валидной формой, чтобы
     * краснота была привязана к лимиту, а не к отсутствию заголовков.
     */
    public function test_oversized_body_is_rejected_before_json_parsing(): void
    {
        $body = str_repeat('x', self::ONE_MIB + 512);

        $request = SignedWebhook::forBody($body)
            ->withHeader('X-Webhook-Id', 'evt_big_garbage');

        $response = $this->postSignedWebhook($request);

        $this->assertSame(413, $response->getStatusCode(), 'Лимит проверяется до JSON-разбора: oversized-мусор — 413, не 400.');
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertNoWebhookSideEffects();
    }
}
