<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoWebhookSideEffects;
use Tests\Support\MarketplaceExamples;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\Support\WebhookSignature;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт ошибок аутентификации вебхука (шаги 2–4 §7):
 * неверная подпись → единый 401 без каких-либо побочных эффектов.
 *
 * Сценарии §8: S-12 (подпись чужим секретом), S-13 (подпись валидна
 * для тела, но тело изменено на один байт или пересериализовано —
 * `\u`/`\/`-экранирование json_encode по умолчанию, R-1), S-14
 * (просроченный timestamp при валидной подписи — побочных эффектов нет).
 *
 * Красная фаза: сверка HMAC — заглушка T-024, поэтому S-12/S-13 сейчас
 * падают на утверждении «401» (фактически 500 от LogicException-заглушки;
 * отсутствие эффектов при этом фактическое — до обработки дело не доходит)
 * и зеленеют с T-024 без правки тестов. Тест S-14 зелёный уже сейчас
 * (окно реализовано в T-010) — это якорь: он фиксирует, что 401 происходит
 * ДО любых записей, и остаётся зелёным после всех задач реализации.
 *
 * Не дублируется: формат единого тела 401 и краях окна ±300/±301 —
 * MarketplaceWebhookRouteTest (T-010) и VerifyMarketplaceSignatureTest;
 * края ±299 и поведение middleware на эталоне — T-016 (unit). Здесь —
 * полный HTTP-путь с реальной подписью и проверка БД.
 */
class MarketplaceWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;
    use PostsSignedWebhooks;
    use AssertsNoWebhookSideEffects;

    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

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
     * S-12: подпись вычислена чужим секретом — форма заголовка корректна
     * (64 hex), но HMAC не совпадает → единый 401 (NFR-04: тело не
     * раскрывает, какая проверка не прошла), ничего не сохранено.
     */
    public function test_forged_signature_is_rejected_without_side_effects(): void
    {
        $request = SignedWebhook::forBody(
            MarketplaceExamples::raw('order-paid'),
            null,
            'whsec_attacker',
        );

        $response = $this->postSignedWebhook($request);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-13 (R-1): подпись валидна для ИСХОДНОГО тела, но доставлено тело,
     * изменённое на один байт (JSON при этом остаётся валидным), — сверка
     * идёт по сырым байтам, подпись расходится → 401 до любого разбора
     * JSON; ничего не сохранено.
     */
    public function test_single_tampered_byte_invalidates_signature(): void
    {
        $original = MarketplaceExamples::raw('order-paid');

        // Байт внутри значения id: JSON остаётся валидным, заголовки
        // доставки — полными, чтобы 401 означал именно расхождение HMAC.
        $offset = (int) strpos($original, 'evt_01J');
        $tampered = substr_replace($original, 'X', $offset + 6, 1);

        $this->assertNotSame($original, $tampered, 'Тело должно отличаться ровно одним байтом.');
        $this->assertNotSame('', trim((string) json_decode($tampered, true)['id'] ?? ''), 'Мутация не должна ломать JSON.');

        $validSignature = SignedWebhook::forBody($original)->signature();

        $request = SignedWebhook::forBody($tampered)
            ->withHeader('X-Webhook-Signature', WebhookSignature::HEADER_PREFIX . $validSignature);

        $response = $this->postSignedWebhook($request);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-13 (R-1, вторая половина сценария): подпись валидна для
     * ИСХОДНОГО тела, но доставлен тот же JSON-документ, пересериализованный
     * json_encode'ом по умолчанию (кириллица → \u-последовательности,
     * `/` → `\/`, без отступов) — байты другие, подпись расходится → 401.
     * Ровно так «ломает» проверку проверяющий (§3.1: перекодирование
     * меняет байты) — сверка обязана идти по сырым байтам.
     */
    public function test_reserialized_body_invalidates_signature_of_original_bytes(): void
    {
        $original = MarketplaceExamples::raw('order-shipped');

        $reserialized = (string) json_encode(
            json_decode($original, true, flags: JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR,
        );

        $this->assertNotSame($original, $reserialized, 'Пересериализация обязана менять байты тела.');
        $this->assertStringContainsString('\u04', $reserialized, 'Кириллица экранирована (json_encode по умолчанию).');
        $this->assertStringContainsString('\/', $reserialized, 'Слэши URL экранированы (json_encode по умолчанию).');

        $validSignature = SignedWebhook::forBody($original)->signature();

        $request = SignedWebhook::forBody($reserialized)
            ->withHeader('X-Webhook-Signature', WebhookSignature::HEADER_PREFIX . $validSignature);

        $response = $this->postSignedWebhook($request);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-14: timestamp за окном ±300 с при ЧЕСТНОЙ подписи этого timestamp
     * → 401 до любых записей (формат единого тела уже покрыт T-010;
     * здесь — подписанный реальным секретом запрос и состояние БД).
     *
     * Зелёный якорь красной фазы: окно реализовано в T-010, тест остаётся
     * зелёным и после T-024+ (401 в шаге 3 §7 — до обработки).
     */
    public function test_stale_timestamp_with_valid_signature_leaves_no_side_effects(): void
    {
        $staleTimestamp = CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp() - 301;

        $request = SignedWebhook::forBody(MarketplaceExamples::raw('order-paid'), $staleTimestamp);

        $response = $this->postSignedWebhook($request);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }
}
