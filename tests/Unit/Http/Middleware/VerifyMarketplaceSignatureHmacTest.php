<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\Webhook\InvalidSignature;
use App\Http\Middleware\VerifyMarketplaceSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\MarketplaceExamples;
use Tests\Support\WebhookSignature;
use Tests\TestCase;

/**
 * Поведение сверки HMAC-подписи (T-016; зелёное с T-024) — шаг 4 §7
 * и FR-WH-04: HMAC от `"{timestamp}.{сырое тело}"`, сравнение
 * hash_equals, регистр hex нормализуется к нижнему. Эталон —
 * task/examples/signature-example.md (тело signature-example-body.json,
 * timestamp 1792577149, секрет whsec_test_5f1d8c2a9b → подпись
 * 4d4f2d50…cb163f7), подписывается независимым клиентским хелпером
 * WebhookSignature (T-015), а не кодом приложения.
 *
 * Формат заголовка и окно времени (шаги 2–3 §7) реализованы в T-010
 * и покрыты VerifyMarketplaceSignatureTest; здесь — сверка подписи.
 * Снятие заглушки T-024 перевернуло скелетный тест края окна ±299 с
 * (теперь он фиксирует прохождение всего middleware, а не достижение
 * заглушки) — правило «Переворачиваемые скелетные тесты» из
 * DECISIONS.md (задача 016); остальные тесты класса позеленели без
 * правки.
 */
class VerifyMarketplaceSignatureHmacTest extends TestCase
{
    /** Эталонные значения signature-example.md (§3.1: пересчитаны и совпадают). */
    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const int REFERENCE_TIMESTAMP = 1792577149;

    private VerifyMarketplaceSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
        ]);

        // Эталонный timestamp примера проходит окно только при
        // замороженном времени (§3.1 ТЗ).
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(self::REFERENCE_TIMESTAMP));

        $this->middleware = new VerifyMarketplaceSignature();
    }

    /**
     * Главное позитивное поведение FR-WH-04: подписанный по эталону
     * запрос проходит всю проверку и доходит до контроллера ($next).
     */
    public function test_valid_reference_signature_reaches_next(): void
    {
        $request = $this->request($this->referenceBody(), $this->referenceHeaders());

        $reached = false;
        $response = $this->middleware->handle($request, $this->next($reached));

        $this->assertTrue($reached, 'Валидная подпись должна пропускать запрос к контроллеру.');
        $this->assertSame('next', $response->getContent());
    }

    /**
     * T1/§3.1: подпись считается по сырому телу — изменение ОДНОГО
     * байта при подписи исходного тела делает подпись недействительной
     * → InvalidSignature (HTTP 401), без разбора JSON.
     */
    public function test_single_byte_body_change_is_rejected(): void
    {
        $tamperedBody = substr_replace($this->referenceBody(), 'I', 2, 1);

        $this->assertNotSame($this->referenceBody(), $tamperedBody, 'Тело должно отличаться ровно одним байтом.');

        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request($tamperedBody, $this->referenceHeaders()), $this->next());
    }

    /**
     * FR-WH-04: регистр hex нормализуется к нижнему — тот же HMAC
     * заглавными символами валиден (формат допускает оба регистра,
     * T-010; сверка обязана не отвергать верхний).
     */
    public function test_uppercase_hex_signature_is_accepted(): void
    {
        $headers = $this->referenceHeaders();
        $headers['X-Webhook-Signature'] = WebhookSignature::HEADER_PREFIX . strtoupper($this->referenceSignature());

        $reached = false;
        $this->middleware->handle($this->request($this->referenceBody(), $headers), $this->next($reached));

        $this->assertTrue($reached, 'Верхний регистр hex — та же подпись (нормализация к нижнему).');
    }

    /**
     * Подпись чужим секретом формально корректна (64 hex), но HMAC
     * не совпадает → InvalidSignature.
     */
    public function test_signature_made_with_other_secret_is_rejected(): void
    {
        $forgedHeaders = [
            'X-Webhook-Id' => 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            'X-Webhook-Timestamp' => (string) self::REFERENCE_TIMESTAMP,
            'X-Webhook-Signature' => WebhookSignature::header($this->referenceBody(), self::REFERENCE_TIMESTAMP, 'whsec_attacker'),
        ];

        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request($this->referenceBody(), $forgedHeaders), $this->next());
    }

    /**
     * Край окна (T-010): 299 секунд — строго внутри ±300. Подпись
     * честно вычислена для этого timestamp, поэтому после T-024 запрос
     * проходит ВСЮ проверку (включая сверку HMAC) и доходит до
     * контроллера. Перевёрнут с ожидания LogicException-заглушки
     * (правило «Переворачиваемые скелетные тесты», DECISIONS 016).
     */
    #[TestWith([-299])]
    #[TestWith([299])]
    public function test_timestamp_just_inside_window_passes_to_next(int $offsetSeconds): void
    {
        $timestamp = self::REFERENCE_TIMESTAMP + $offsetSeconds;

        $headers = [
            'X-Webhook-Id' => 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => WebhookSignature::header($this->referenceBody(), $timestamp, self::SECRET),
        ];

        $reached = false;
        $response = $this->middleware->handle($this->request($this->referenceBody(), $headers), $this->next($reached));

        $this->assertTrue($reached, 'Край окна ±299 с валидной подписью проходит к контроллеру.');
        $this->assertSame('next', $response->getContent());
    }

    /** Сырое тело эталона подписи, байт в байт (765 байт, без финального \n). */
    private function referenceBody(): string
    {
        return MarketplaceExamples::raw('signature-example-body');
    }

    /** Подпись эталона, вычисленная независимым хелпером (совпадает с signature-example.md). */
    private function referenceSignature(): string
    {
        return WebhookSignature::sign($this->referenceBody(), self::REFERENCE_TIMESTAMP, self::SECRET);
    }

    /**
     * @return array<string, non-empty-string> заголовки эталонной доставки
     */
    private function referenceHeaders(): array
    {
        return [
            'X-Webhook-Id' => 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            'X-Webhook-Timestamp' => (string) self::REFERENCE_TIMESTAMP,
            'X-Webhook-Signature' => WebhookSignature::HEADER_PREFIX . $this->referenceSignature(),
        ];
    }

    /**
     * @param array<string, non-empty-string> $headers
     */
    private function request(string $rawBody, array $headers): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('/api/webhooks/marketplace', 'POST', [], [], [], $server, $rawBody);
    }

    /**
     * Замыкание $next с флагом по ссылке: фиксирует, что подпись
     * пропустила запрос к контроллеру.
     *
     * @param-out bool $reached
     */
    private function next(?bool &$reached = null): \Closure
    {
        return static function (Request $request) use (&$reached): \Symfony\Component\HttpFoundation\Response {
            $reached = true;

            return new \Symfony\Component\HttpFoundation\Response('next');
        };
    }
}
