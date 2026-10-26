<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsNoWebhookSideEffects;
use Tests\Support\MutatedWebhookBody;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\TestCase;

/**
 * T-017. HTTP-контракт ошибок payload вебхука (шаги 5–7, 9, 11 §7):
 * невалидный JSON и несовпадение ID → 400 (FR-WH-05), неверный базовый
 * конверт и некорректный снимок известного статуса → 422 (FR-WH-06/08).
 * Ни один из таких запросов не записывается в webhook_events и не
 * меняет заказы (FR-ORD-08).
 *
 * Сценарии §8: S-16 (X-Webhook-Id ≠ body.id), S-17 (не-JSON / нет
 * data.order у известного типа / status не строка), FR-WH-06/08
 * (конверт и снимок).
 *
 * Красная фаза: разбор payload — заглушки T-021, все тесты класса
 * сейчас получают 500 от LogicException-заглушки HMAC/парсера и падают
 * на утверждении о будущем коде ответа; зеленеют с T-021+T-024 без
 * правки тестов. Маппинг кодов сам по себе уже покрыт unit-тестом
 * WebhookResponseMapperTest (T-010) — здесь проверяется полный HTTP-путь
 * от подписанного запроса до тела ответа и состояния БД.
 */
class MarketplaceWebhookPayloadErrorsTest extends TestCase
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
     * S-17 (1/3): подпись валидна, но тело — не JSON → 400 invalid_json
     * (FR-WH-05), без записей в журнале и заказах (FR-ORD-08).
     */
    public function test_invalid_json_returns_400_without_side_effects(): void
    {
        // X-Webhook-Id задаётся явно: у не-JSON тела нет body.id, а 400
        // обязан приходить из-за тела, а не из-за отсутствия заголовков.
        $request = SignedWebhook::forBody('{"id":"evt_broken", не json' . "\n")
            ->withHeader('X-Webhook-Id', 'evt_broken');

        $response = $this->postSignedWebhook($request);

        $this->assertSame(400, $response->getStatusCode(), 'Невалидный JSON обязан давать 400, а не 500/422.');
        $this->assertSame('invalid_json', $response->json('error.code'));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * FR-WH-05 (вторая ветка): тело с невалидной UTF-8-последовательностью
     * → 400 invalid_json, а не 422/500. X-Webhook-Id задан явно: у такого
     * тела нет читаемого body.id, а краснота привязана к кодировке тела.
     */
    public function test_non_utf8_body_returns_400_without_side_effects(): void
    {
        $request = SignedWebhook::forBody('{"id":"evt_encoding","note":"' . "\xB1" . '"}')
            ->withHeader('X-Webhook-Id', 'evt_encoding');

        $response = $this->postSignedWebhook($request);

        $this->assertSame(400, $response->getStatusCode(), 'Не-UTF-8 тело — невалидный JSON (400 invalid_json, FR-WH-05).');
        $this->assertSame('invalid_json', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-16: заголовок X-Webhook-Id не совпадает с body.id → 400
     * event_id_mismatch (FR-WH-05), ничего не сохранено.
     */
    public function test_webhook_id_mismatch_returns_400_without_side_effects(): void
    {
        $request = SignedWebhook::forExample('order-paid')
            ->withHeader('X-Webhook-Id', 'evt_totally_different');

        $response = $this->postSignedWebhook($request);

        $this->assertSame(400, $response->getStatusCode(), 'Несовпадение X-Webhook-Id и body.id обязано давать 400.');
        $this->assertSame('event_id_mismatch', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * FR-WH-06: created_at без смещения часового пояса — базовый конверт
     * невалиден → 422 invalid_envelope, событие не сохраняется.
     */
    public function test_invalid_envelope_returns_422_without_side_effects(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')
            ->with('created_at', '2026-10-21T10:05:48')
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(422, $response->getStatusCode(), 'created_at без смещения — невалидный конверт (422).');
        $this->assertSame('invalid_envelope', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-17 (2/3): у известного типа нет data.order → 422 (шаг 9 §7),
     * событие не сохраняется (FR-ORD-08: только валидный базовый конверт
     * даёт запись в журнале).
     */
    public function test_known_type_without_order_object_returns_422(): void
    {
        $body = MutatedWebhookBody::fromRaw(
            '{"id":"evt_no_order","type":"order.paid","created_at":"2026-10-21T10:05:48+00:00","data":{"note":"без снимка"}}',
        )->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(422, $response->getStatusCode(), 'Известный тип без data.order — структурная ошибка 422.');
        $this->assertSame('invalid_envelope', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * S-17 (3/3): у известного типа status не строка (null) → 422,
     * а не «неизвестный статус → ignored»: ветка ignored (шаг 10 §7)
     * наступает только для СТРОКОВОГО значения вне enum (FR-WH-07).
     */
    public function test_known_type_with_non_string_status_returns_422(): void
    {
        $body = MutatedWebhookBody::fromExample('order-paid')
            ->withPath(['data', 'order', 'status'], null)
            ->body();

        $response = $this->postSignedWebhook(SignedWebhook::forBody($body));

        $this->assertSame(422, $response->getStatusCode(), 'Нестроковый status у известного типа — 422, не ignored.');
        $this->assertSame('invalid_envelope', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }

    /**
     * FR-WH-08: известный тип + известный статус, но обязательное поле
     * снимка нарушено → 422 invalid_snapshot; запрос не записывается
     * в webhook_events и не трогает заказ. Расхождение проверок:
     * формат суммы, количество, валюта (3 ЗАГЛАВНЫЕ буквы).
     */
    #[TestWith(['сумма с одним знаком после точки', 'total', '4909.5'])]
    #[TestWith(['нулевое количество позиции', 'quantity-item', 0])]
    #[TestWith(['валюта в нижнем регистре', 'currency', 'rub'])]
    public function test_invalid_snapshot_field_returns_422_without_side_effects(
        string $case,
        string $field,
        string|int $value,
    ): void {
        $builder = MutatedWebhookBody::fromExample('order-paid');

        $builder = $field === 'quantity-item'
            ? $builder->withPath(['data', 'order', 'items', 0, 'quantity'], $value)
            : $builder->withPath(['data', 'order', $field], $value);

        $response = $this->postSignedWebhook(SignedWebhook::forBody($builder->body()));

        $this->assertSame(
            422,
            $response->getStatusCode(),
            sprintf('Некорректное поле снимка (%s) обязано давать 422 invalid_snapshot.', $case),
        );
        $this->assertSame('invalid_snapshot', $response->json('error.code'));
        $this->assertNoWebhookSideEffects();
    }
}
