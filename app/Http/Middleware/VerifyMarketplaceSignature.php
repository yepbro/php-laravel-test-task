<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Webhook\InvalidSignature;
use App\Exceptions\Webhook\StaleTimestamp;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверка подписи вебхука MarketHub до контроллера (§7 шаги 1–4;
 * каркас T-010, сверка HMAC — T-024).
 *
 * Последовательность из §7 ТЗ:
 *
 * | Шаг §7 | Проверка | Отказ |
 * |---|---|---|
 * | — | секрет задан (fail closed, Q11) | 401 InvalidSignature |
 * | 2 | обязательные заголовки и их формат | 401 InvalidSignature |
 * | 3 | окно `\|now − X-Webhook-Timestamp\| ≤ tolerance` | 401 StaleTimestamp |
 * | 4 | HMAC от `"{timestamp}.{сырое тело}"` (hash_equals) | 401 InvalidSignature |
 *
 * Шаг 4 (T-024, FR-WH-04): HMAC-SHA256 от `"{timestamp}.{сырое тело}"`
 * ключом MARKETPLACE_WEBHOOK_SECRET сравнивается с заголовком через
 * hash_equals — тайминг-безопасно, по сырым байтам тела (T1: байт в
 * байт, включая финальный `\n`), до любого JSON-разбора. Регистр hex
 * входящего заголовка нормализуется к нижнему (hash_hmac отдаёт нижний).
 *
 * Тело читается только через `getContent()` и не попадает ни в логи,
 * ни в ответы (NFR-04); секрет и подпись — тоже. Исключения не
 * перехватываются здесь: единый HTTP-вид 401/400/422 строит
 * WebhookResponseMapper, зарегистрированный в bootstrap/app.php.
 */
final class VerifyMarketplaceSignature
{
    /** Префикс значения X-Webhook-Signature (FR-WH-02, дословно как у MarketHub). */
    private const string SIGNATURE_PREFIX = 'sha256=';

    /** Формат X-Webhook-Signature: `sha256=` + ровно 64 hex-символа (FR-WH-02). */
    private const string SIGNATURE_PATTERN = '/^' . self::SIGNATURE_PREFIX . '[0-9a-fA-F]{64}$/';

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->secret();

        // Шаг 2 §7: обязательные заголовки и формат timestamp/signature.
        $this->assertHeaderPresent($request, 'X-Webhook-Id');
        $timestamp = $this->timestamp($request);
        $signature = $this->header($request, 'X-Webhook-Signature');
        $this->assertSignatureFormat($signature);

        // Шаг 3 §7: окно времени.
        $this->assertWithinTolerance($timestamp);

        // Шаг 4 §7: сверка HMAC по сырым байтам тела (до JSON-разбора).
        $this->assertSignatureMatches($timestamp, $request->getContent(), $secret, $signature);

        return $next($request);
    }

    /**
     * Секрет из конфига; пустое значение — fail closed (Q11/FR-WH-04):
     * без заданного секрета подпись не с чем сверять, все вебхуки
     * отклоняются единой ошибкой 401.
     */
    private function secret(): string
    {
        $secret = config('marketplace.webhook_secret');

        if (!is_string($secret) || $secret === '') {
            throw new InvalidSignature();
        }

        return $secret;
    }

    /**
     * Обязательный заголовок как непустая строка (FR-WH-02). Несколько
     * одноимённых заголовков склеиваются в «a,b»: для timestamp/signature
     * это валит форматную проверку; для X-Webhook-Id — нет (идёт в
     * сравнение с `body.id` на шаге 6 §7), поэтому здесь только непустота.
     */
    private function header(Request $request, string $name): string
    {
        $value = $request->header($name);

        if (!is_string($value) || $value === '') {
            throw new InvalidSignature();
        }

        return $value;
    }

    /**
     * Наличие обязательного заголовка без разбора значения (FR-WH-02);
     * X-Webhook-Id дополнительно сверяется с `body.id` на шаге 6 §7.
     */
    private function assertHeaderPresent(Request $request, string $name): void
    {
        $this->header($request, $name);
    }

    /**
     * X-Webhook-Timestamp: целые Unix-секунды (FR-WH-02). Строгое
     * «только цифры» отсекает `+100`, `1.5`, `1e3` и пустоту.
     */
    private function timestamp(Request $request): int
    {
        $raw = $this->header($request, 'X-Webhook-Timestamp');

        if (ctype_digit($raw) !== true) {
            throw new InvalidSignature();
        }

        return (int) $raw;
    }

    /**
     * Формат подписи `sha256=<64 hex>` (FR-WH-02); регистр hex допускается
     * любой — нормализация к нижнему при сравнении (FR-WH-04, T-024).
     */
    private function assertSignatureFormat(string $signature): void
    {
        if (preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            throw new InvalidSignature();
        }
    }

    /**
     * Окно ±tolerance в обе стороны, включая «из будущего» (FR-WH-03,
     * T2). Граница ровно tolerance — допустима.
     */
    private function assertWithinTolerance(int $timestamp): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        // Дефолт 300 — тот же, что в config/marketplace.php: исчезновение
        // ключа конфига не должно молча сужать окно до нуля (все вебхуки
        // — 401 без видимой причины).
        $tolerance = (int) config('marketplace.webhook_tolerance', 300);

        if (abs($now - $timestamp) > $tolerance) {
            throw new StaleTimestamp();
        }
    }

    /**
     * Шаг 4 §7 — сверка HMAC (FR-WH-04): hash_hmac('sha256',
     * `$timestamp . '.' . $rawBody`, секрет) против заголовка через
     * hash_equals — тайминг-безопасное сравнение строк равной длины
     * (формат уже гарантировал `sha256=` + ровно 64 hex). Регистр hex
     * заголовка нормализуется к нижнему: hash_hmac отдаёт только нижний,
     * префикс формата тоже нижний — MarketHub вправе слать верхний hex.
     *
     * Тело — сырые байты (`$request->getContent()`), без decode и
     * пересериализации (T1/§3.1): изменение одного байта или финального
     * `\n` расходится с подписью. Несовпадение — единый 401
     * InvalidSignature без раскрытия причины (NFR-04).
     */
    private function assertSignatureMatches(int $timestamp, string $rawBody, string $secret, string $signature): void
    {
        $expected = self::SIGNATURE_PREFIX . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        if (hash_equals($expected, strtolower($signature)) !== true) {
            throw new InvalidSignature();
        }
    }
}
