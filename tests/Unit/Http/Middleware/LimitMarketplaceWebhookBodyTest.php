<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\Webhook\PayloadTooLarge;
use App\Http\Middleware\LimitMarketplaceWebhookBody;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * LimitMarketplaceWebhookBody (T-025): лимит тела 1 MiB — §7 шаг 1,
 * FR-WH-12. Проверяются оба источника размера (Content-Length и
 * фактические сырые байты); ровно 1 MiB — допустимо, строго больше —
 * PayloadTooLarge (→ 413 единым рендером исключений).
 *
 * Граница «ровно 1 MiB допустимо» сознательно не пиннится на
 * HTTP-уровне (решение T-017) — она здесь, на самой проверке.
 * Случаи без Content-Length, с заниженным и с мусорным заголовком
 * тестируются через небольшой переопределённый лимит: сравнение то же,
 * без аллокаций строк по 1 MiB на каждый датасет.
 */
class LimitMarketplaceWebhookBodyTest extends TestCase
{
    private const int ONE_MIB = 1024 * 1024;

    private LimitMarketplaceWebhookBody $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new LimitMarketplaceWebhookBody();
    }

    /**
     * Реалистичная граница: тело ровно в 1 MiB (умолчание конфига)
     * с честным Content-Length проходит дальше — отказ только при
     * строго большем размере (FR-WH-12).
     */
    public function test_body_of_exactly_one_mib_is_accepted(): void
    {
        $reached = false;

        $response = $this->middleware->handle(
            $this->request(str_repeat('x', self::ONE_MIB), (string) self::ONE_MIB),
            $this->next($reached),
        );

        $this->assertTrue($reached, 'Тело ровно в 1 MiB не должно отвергаться.');
        $this->assertSame('next', $response->getContent());
    }

    /**
     * Строго больше 1 MiB → PayloadTooLarge, запрос не доходит до
     * контроллера. Content-Length не задан: отказ фиксируется именно
     * за фактическим размером сырых байтов.
     */
    public function test_body_above_one_mib_is_rejected(): void
    {
        $reached = false;

        try {
            $this->middleware->handle(
                $this->request(str_repeat('x', self::ONE_MIB + 1)),
                $this->next($reached),
            );
            $this->fail('Тело больше 1 MiB должно было завершиться PayloadTooLarge.');
        } catch (PayloadTooLarge) {
            // 413 единым рендером — см. WebhookResponseMapper.
        }

        $this->assertFalse($reached, 'Oversized-тело не должно пропускаться к контроллеру.');
    }

    /**
     * Content-Length больше лимита — отказ по заголовку до сверки
     * фактического размера (FR-WH-12): прикрывает случай, когда
     * настройка PHP обрезала переданные байты и фактический размер
     * уже не виден.
     */
    public function test_content_length_above_limit_is_rejected_before_body_size(): void
    {
        $this->expectException(PayloadTooLarge::class);

        $this->middleware->handle(
            $this->request('{"id":"evt_small"}', (string) (self::ONE_MIB + 1)),
            $this->next(),
        );
    }

    /**
     * Заниженный Content-Length не даёт обхода: заголовок проходит
     * проверку, но фактический размер сырых байтов решает (FR-WH-12).
     */
    public function test_understated_content_length_is_caught_by_actual_size(): void
    {
        config(['marketplace.webhook_max_body_bytes' => 16]);

        $this->expectException(PayloadTooLarge::class);

        $this->middleware->handle(
            $this->request(str_repeat('x', 17), '5'),
            $this->next(),
        );
    }

    /**
     * Без Content-Length решение принимает фактический размер сырых
     * байтов; граница та же — ровно лимит допустим.
     */
    public function test_boundary_body_without_content_length_is_accepted(): void
    {
        config(['marketplace.webhook_max_body_bytes' => 16]);

        $reached = false;

        $response = $this->middleware->handle(
            $this->request(str_repeat('x', 16)),
            $this->next($reached),
        );

        $this->assertTrue($reached);
        $this->assertSame('next', $response->getContent());
    }

    /** Фактический размер больше лимита при отсутствии Content-Length → PayloadTooLarge. */
    public function test_body_above_limit_without_content_length_is_rejected(): void
    {
        config(['marketplace.webhook_max_body_bytes' => 16]);

        $this->expectException(PayloadTooLarge::class);

        $this->middleware->handle(
            $this->request(str_repeat('x', 17)),
            $this->next(),
        );
    }

    /**
     * Мусорный Content-Length не парсится числом и не даёт обхода:
     * решение остаётся за фактическим размером сырых байтов.
     */
    #[TestWith(['not-a-number'])]
    #[TestWith([''])]
    #[TestWith(['-1'])]
    #[TestWith(['1e6'])]
    public function test_malformed_content_length_falls_back_to_actual_size(string $contentLength): void
    {
        config(['marketplace.webhook_max_body_bytes' => 16]);

        $this->expectException(PayloadTooLarge::class);

        $this->middleware->handle(
            $this->request(str_repeat('x', 17), $contentLength),
            $this->next(),
        );
    }

    /**
     * POST-запрос вебхука с сырым телом. Content-Length — как в реальной
     * доставке: Symfony не синтезирует его из $content; null — заголовок
     * отсутствует (решение принимает фактический размер).
     */
    private function request(string $rawBody, ?string $contentLength = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return Request::create('/api/webhooks/marketplace', 'POST', [], [], [], $server, $rawBody);
    }

    /**
     * Замыкание $next с флагом по ссылке: лимит не должен пропускать
     * oversized-запросы к контроллеру.
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
