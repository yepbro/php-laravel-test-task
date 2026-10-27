<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\MarketplaceExamples;
use Tests\Support\PostsSignedWebhooks;
use Tests\Support\SignedWebhook;
use Tests\Support\WebhookSignature;
use Tests\TestCase;

/**
 * T-015. Подписанный запрос вебхука: самопроверка хелпера по ЭТАЛОНУ
 * task/examples/signature-example.md (тело + timestamp + секрет →
 * дословный HMAC), детерминированные дефолты (замороженное время,
 * config-секрет), подмена заголовков для негативных сценариев
 * и доставка тела БАЙТ В БАЙТ через call($content) — включая
 * финальный `\n` примеров событий (S-22/S-23).
 *
 * Проверка доставки не зависит от будущих кодов обработки: байтовость
 * фиксируется echo-маршрутом, а фактический маршрут с валидной
 * подписью упирается в заглушку HMAC T-024 (500, не 401) — то есть
 * форма заголовков и окно времени пройдены, тело дошло до шага подписи.
 */
class SignedWebhookTest extends TestCase
{
    use PostsSignedWebhooks;

    /** Эталонные значения signature-example.md (§3.1: пересчитаны и совпадают). */
    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const int REFERENCE_TIMESTAMP = 1792577149;

    private const string REFERENCE_SIGNATURE = '4d4f2d50f98596ab503d29dc96db6fda247e3c482bff130bd97353564cb163f7';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
            'app.debug' => false,
        ]);
    }

    /**
     * Главная самопроверка: хелпер подписывает ровно как MarketHub —
     * дословный HMAC из signature-example.md (exact match).
     */
    public function test_signature_matches_reference_example(): void
    {
        $body = MarketplaceExamples::raw('signature-example-body');

        $signature = WebhookSignature::sign($body, self::REFERENCE_TIMESTAMP, self::SECRET);

        $this->assertSame(self::REFERENCE_SIGNATURE, $signature);
        $this->assertSame('sha256=' . self::REFERENCE_SIGNATURE, WebhookSignature::header($body, self::REFERENCE_TIMESTAMP, self::SECRET));
    }

    /** Подпись — 64 hex-символа в нижнем регистре (FR-WH-02). */
    public function test_signature_is_lowercase_hex(): void
    {
        $signature = WebhookSignature::sign('{"id":"evt"}' . "\n", self::REFERENCE_TIMESTAMP, self::SECRET);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    /**
     * Финальный `\n` входит в подписываемые байты: подпись тела
     * с `\n` отличается от подписи тела без него (S-23/S-13).
     */
    public function test_trailing_newline_changes_signature(): void
    {
        $body = '{"id":"evt_test"}';

        $this->assertNotSame(
            WebhookSignature::sign($body, self::REFERENCE_TIMESTAMP, self::SECRET),
            WebhookSignature::sign($body . "\n", self::REFERENCE_TIMESTAMP, self::SECRET),
        );
    }

    /** Заголовки запроса: id из body.id, явный timestamp, подпись с префиксом. */
    public function test_for_body_builds_delivery_headers(): void
    {
        $request = SignedWebhook::forBody(
            MarketplaceExamples::raw('signature-example-body'),
            self::REFERENCE_TIMESTAMP,
            self::SECRET,
        );

        $this->assertSame([
            'X-Webhook-Timestamp' => '1792577149',
            'X-Webhook-Signature' => 'sha256=' . self::REFERENCE_SIGNATURE,
            'X-Webhook-Id' => 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
        ], $request->headers());
        $this->assertSame(self::REFERENCE_TIMESTAMP, $request->timestamp());
        $this->assertSame(self::REFERENCE_SIGNATURE, $request->signature());
    }

    /** Тело запроса — те же байты, что были переданы (без перекодировки). */
    public function test_content_is_raw_body_bytes(): void
    {
        $body = MarketplaceExamples::raw('order-paid');

        $this->assertSame($body, SignedWebhook::forBody($body, self::REFERENCE_TIMESTAMP)->content());
    }

    /**
     * Паттерн travelTo + дефолт timestamp: время заморожено — подпись
     * берётся из замороженного «сейчас» без явного аргумента (§11 ТЗ).
     */
    public function test_default_timestamp_comes_from_frozen_time(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(self::REFERENCE_TIMESTAMP));

        $request = SignedWebhook::forBody(MarketplaceExamples::raw('signature-example-body'));

        $this->assertSame(self::REFERENCE_TIMESTAMP, $request->timestamp());
        $this->assertSame(self::REFERENCE_SIGNATURE, $request->signature());
    }

    /** Секрет по умолчанию — config('marketplace.webhook_secret') (T-009). */
    public function test_default_secret_comes_from_config(): void
    {
        $body = '{"id":"evt_test"}';

        config(['marketplace.webhook_secret' => 'whsec_other']);

        $request = SignedWebhook::forBody($body, self::REFERENCE_TIMESTAMP);

        $this->assertSame(WebhookSignature::sign($body, self::REFERENCE_TIMESTAMP, 'whsec_other'), $request->signature());
    }

    /** Пустой секрет — ошибка инфраструктуры теста (fail fast), а не подпись пустым ключом. */
    public function test_empty_secret_fails_fast(): void
    {
        config(['marketplace.webhook_secret' => '']);

        $this->expectException(\LogicException::class);

        SignedWebhook::forBody('{}', self::REFERENCE_TIMESTAMP);
    }

    /** Тело без читаемого id: X-Webhook-Id опускается (задаётся явно при необходимости). */
    public function test_undecodable_body_omits_webhook_id(): void
    {
        $request = SignedWebhook::forBody('not-json', self::REFERENCE_TIMESTAMP);

        $this->assertArrayNotHasKey('X-Webhook-Id', $request->headers());
    }

    /** Сокращение forExample(): подписать пример как есть, байт в байт. */
    public function test_for_example_signs_raw_example_bytes(): void
    {
        $viaExample = SignedWebhook::forExample('order-paid', self::REFERENCE_TIMESTAMP);
        $viaBody = SignedWebhook::forBody(MarketplaceExamples::raw('order-paid'), self::REFERENCE_TIMESTAMP);

        $this->assertSame($viaBody->headers(), $viaExample->headers());
        $this->assertSame(MarketplaceExamples::raw('order-paid'), $viaExample->content());
    }

    /**
     * Подмена/удаление заголовков для негативных сценариев (S-13/S-15/S-16);
     * имя сопоставляется без учёта регистра.
     */
    public function test_header_manipulation_builds_negative_variants(): void
    {
        $request = SignedWebhook::forBody(MarketplaceExamples::raw('order-paid'), self::REFERENCE_TIMESTAMP);

        $tampered = $request->withHeader('X-Webhook-Id', 'evt_other');
        $this->assertSame('evt_other', $tampered->headers()['X-Webhook-Id']);
        $this->assertSame($request->headers()['X-Webhook-Signature'], $tampered->headers()['X-Webhook-Signature']);

        $unsigned = $request->withoutHeader('x-webhook-signature');
        $this->assertArrayNotHasKey('X-Webhook-Signature', $unsigned->headers());

        // Исходный запрос манипуляциями не изменяется.
        $this->assertArrayHasKey('X-Webhook-Signature', $request->headers());
    }

    /** Server-механика call(): HTTP_* ключи + Content-Type как у send-event.php. */
    public function test_server_array_suits_call_mechanics(): void
    {
        $request = SignedWebhook::forBody(MarketplaceExamples::raw('order-paid'), self::REFERENCE_TIMESTAMP);

        $this->assertSame('application/json', $request->server()['CONTENT_TYPE']);
        $this->assertSame((string) strlen(MarketplaceExamples::raw('order-paid')), $request->server()['CONTENT_LENGTH']);
        $this->assertSame((string) self::REFERENCE_TIMESTAMP, $request->server()['HTTP_X_WEBHOOK_TIMESTAMP']);
        $this->assertSame('sha256=' . $request->signature(), $request->server()['HTTP_X_WEBHOOK_SIGNATURE']);
        $this->assertSame('evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC', $request->server()['HTTP_X_WEBHOOK_ID']);
    }

    /**
     * Доставка тела байт в байт: echo-маршрут возвращает getContent()
     * как есть — включая финальный `\n` примера события. Это проверка
     * механики call($content), не зависящая от кода обработки вебхука.
     */
    public function test_post_delivers_body_byte_per_byte(): void
    {
        Route::post('/_test/webhook-echo', fn(Request $request) => response(
            $request->getContent(),
            200,
            ['Content-Type' => 'text/plain'],
        ));

        $body = MarketplaceExamples::raw('order-paid');
        $request = SignedWebhook::forBody($body, self::REFERENCE_TIMESTAMP);

        $response = $this->postSignedWebhook($request, '/_test/webhook-echo');

        $response->assertOk();
        $this->assertSame($body, $response->getContent());
        $this->assertSame("\n", substr($response->getContent(), -1));
        $this->assertSame(strlen($body), strlen($response->getContent()));
    }

    /** Тело без финального `\n` (эталон подписи) доставляется так же дословно. */
    public function test_post_delivers_body_without_trailing_newline(): void
    {
        Route::post('/_test/webhook-echo', fn(Request $request) => response(
            $request->getContent(),
            200,
            ['Content-Type' => 'text/plain'],
        ));

        $body = MarketplaceExamples::raw('signature-example-body');
        $request = SignedWebhook::forBody($body, self::REFERENCE_TIMESTAMP);

        $response = $this->postSignedWebhook($request, '/_test/webhook-echo');

        $this->assertSame('}', substr($response->getContent(), -1));
        $this->assertSame($body, $response->getContent());
    }

    /**
     * Фактический маршрут: валидная подпись эталона при замороженном
     * времени проходит заголовки и окно (не 401) и доходит до заглушки
     * HMAC T-024 — текущее фактическое поведение 500. С реализацией
     * T-024 этот же запрос станет основой S-22.
     */
    public function test_signed_reference_request_reaches_signature_step_on_real_route(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(self::REFERENCE_TIMESTAMP));

        $request = SignedWebhook::forBody(
            MarketplaceExamples::raw('signature-example-body'),
            self::REFERENCE_TIMESTAMP,
        );

        $response = $this->postSignedWebhook($request);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('processed', $response->getContent());
    }

    /**
     * Стабильная негативная ветка: без заголовка подписи middleware
     * отвечает единым 401 (реализовано в T-010, не зависит от T-024) —
     * хелпер годится и для негативных HTTP-тестов.
     */
    public function test_request_without_signature_header_is_unauthorized_on_real_route(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(self::REFERENCE_TIMESTAMP));

        $request = SignedWebhook::forBody(MarketplaceExamples::raw('order-paid'), self::REFERENCE_TIMESTAMP)
            ->withoutHeader('X-Webhook-Signature');

        $response = $this->postSignedWebhook($request);

        $response->assertUnauthorized();
        $this->assertSame('unauthorized', $response->json('error.code'));
    }
}
