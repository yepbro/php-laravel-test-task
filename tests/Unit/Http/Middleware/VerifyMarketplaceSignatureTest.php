<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\StaleTimestamp;
use App\Http\Middleware\VerifyMarketplaceSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * VerifyMarketplaceSignature (T-010): каркас шагов 2–3 §7 — обязательные
 * заголовки, формат timestamp/signature, окно времени. Сверка HMAC
 * (шаг 4) — заглушка T-024: fail closed, до неё ни один запрос
 * не проходит к контроллеру.
 */
class VerifyMarketplaceSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test_5f1d8c2a9b';

    private const string FROZEN_NOW = '2026-10-27 12:00:00 UTC';

    /** Ровно 64 hex-символа — корректная форма X-Webhook-Signature (FR-WH-02). */
    private const string HEX64 = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private const string VALID_SIGNATURE = 'sha256=' . self::HEX64;

    private VerifyMarketplaceSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.webhook_secret' => self::SECRET,
            'marketplace.webhook_tolerance' => 300,
        ]);

        // Фиксированное «сейчас»: проверка окна не зависит от реального
        // времени запуска набора.
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $this->middleware = new VerifyMarketplaceSignature();
    }

    /**
     * Пустой секрет — fail closed (Q11): вебхуки отклоняются, даже если
     * заголовки корректны.
     */
    public function test_empty_secret_fails_closed(): void
    {
        config(['marketplace.webhook_secret' => '']);

        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request(), $this->next());
    }

    #[TestWith(['X-Webhook-Id'])]
    #[TestWith(['X-Webhook-Timestamp'])]
    #[TestWith(['X-Webhook-Signature'])]
    public function test_missing_required_header_is_rejected(string $withoutHeader): void
    {
        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request([$withoutHeader => null]), $this->next());
    }

    /** @return array<string, array{string}> */
    public static function badSignatures(): array
    {
        return [
            'без префикса' => [self::HEX64],
            'не hex' => ['sha256=' . str_repeat('z', 64)],
            '63 символа' => ['sha256=' . substr(self::HEX64, 1)],
            '65 символов' => ['sha256=' . self::HEX64 . 'a'],
            'префикс в верхнем регистре' => ['SHA256=' . self::HEX64],
            'пустая подпись' => [''],
        ];
    }

    #[DataProvider('badSignatures')]
    public function test_malformed_signature_header_is_rejected(string $signature): void
    {
        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request(['X-Webhook-Signature' => $signature]), $this->next());
    }

    /** @return array<string, array{string}> */
    public static function badTimestamps(): array
    {
        return [
            'пустое значение' => [''],
            'не цифры' => ['abc'],
            'дробное' => ['1792577149.5'],
            'отрицательное' => ['-1792577149'],
            'со знаком плюс' => ['+1792577149'],
            'экспоненциальная запись' => ['1e9'],
            'пробел вокруг' => [' 1792577149'],
        ];
    }

    #[DataProvider('badTimestamps')]
    public function test_malformed_timestamp_header_is_rejected(string $timestamp): void
    {
        $this->expectException(InvalidSignature::class);

        $this->middleware->handle($this->request(['X-Webhook-Timestamp' => $timestamp]), $this->next());
    }

    #[TestWith([-301])]
    #[TestWith([301])]
    public function test_timestamp_outside_window_is_stale(int $offsetSeconds): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $this->expectException(StaleTimestamp::class);

        $this->middleware->handle(
            $this->request(['X-Webhook-Timestamp' => (string) $this->frozenTimestamp($offsetSeconds)]),
            $this->next(),
        );
    }

    /**
     * Граница окна ровно ±tolerance — допустима (S-14): запрос проходит
     * проверку времени и упирается в заглушку HMAC (T-024).
     */
    #[TestWith([-300])]
    #[TestWith([300])]
    public function test_timestamp_at_window_boundary_passes_time_check(int $offsetSeconds): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-024');

        $this->middleware->handle(
            $this->request(['X-Webhook-Timestamp' => (string) $this->frozenTimestamp($offsetSeconds)]),
            $this->next(),
        );
    }

    /**
     * Основное свойство скелета: запрос с корректной формой заголовков
     * не проходит дальше — сверка HMAC не реализована (T-024) и fail
     * closed. Заглушка НЕ принимает недопроверенные запросы.
     */
    public function test_valid_request_stops_at_hmac_stub_and_never_passes(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW));

        $nextReached = false;
        $next = $this->next($nextReached);

        try {
            $this->middleware->handle($this->request(), $next);
            $this->fail('Заглушка HMAC должна была отклонить запрос LogicException.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('T-024', $exception->getMessage());
        }

        $this->assertFalse($nextReached, 'Скелет не должен пропускать запрос к контроллеру до реализации HMAC.');
    }

    /**
     * Регистр hex нормализуется к нижнему при сравнении (FR-WH-04):
     * заглавные hex-символы проходят форматную проверку (до окна/заглушки).
     */
    public function test_uppercase_hex_signature_passes_format_check(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('T-024');

        $this->middleware->handle(
            $this->request(['X-Webhook-Signature' => 'sha256=' . strtoupper(self::HEX64)]),
            $this->next(),
        );
    }

    private function frozenTimestamp(int $offsetSeconds): int
    {
        return CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp() + $offsetSeconds;
    }

    /**
     * @param array<string, string|null> $overrides null удаляет заголовок
     */
    private function request(array $overrides = []): Request
    {
        // Значения по умолчанию, затем ЯВНЫЕ override'ы (включая null —
        // «заголовок отсутствует»); array_filter убирает null. Проверять
        // через `??` нельзя: null-override означал бы дефолт.
        $headers = [
            'X-Webhook-Id' => 'evt_01JAXM4Q9T6V2K8R3N5W7Y0ZBC',
            'X-Webhook-Timestamp' => (string) CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp(),
            'X-Webhook-Signature' => self::VALID_SIGNATURE,
        ];

        foreach ($overrides as $name => $value) {
            $headers[$name] = $value;
        }

        $headers = array_filter($headers, static fn(?string $value): bool => $value !== null);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return Request::create('/api/webhooks/marketplace', 'POST', [], [], [], $server, '{"id":"evt_test"}');
    }

    /**
     * Замыкание $next с флагом по ссылке: скелет не должен до него доходить.
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
