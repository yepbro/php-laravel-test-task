<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;

/**
 * Подписанный HTTP-запрос вебхука MarketHub для Laravel-тестов (T-015):
 * сырые байты тела + заголовки доставки, как их отправляет MarketHub
 * (`task/examples/send-event.php`).
 *
 * Механика байт в байт: тело передаётся в `$this->call(..., $content)`
 * строкой — в отличие от post()/postJson(), которые перекодируют
 * массив/пересериализуют JSON. Заголовки отдаются server()-массивом
 * (HTTP_* ключи + CONTENT_TYPE), совместимым с MakesHttpRequests::call().
 *
 * Значения по умолчанию детерминированы тестовым временем и конфигом:
 * timestamp — CarbonImmutable::now()->getTimestamp() (замораживается
 * travelTo, §11 ТЗ), секрет — config('marketplace.webhook_secret').
 * Оба переопределяются явными аргументами: эталонный timestamp примера
 * подписи всегда задаётся явно.
 *
 * X-Webhook-Id берётся из `body.id` (как в send-event.php); для тел
 * без читаемого id заголовок опускается — негативные сценарии
 * (S-15/S-16: подмена/отсутствие заголовка, кривая подпись) задаются
 * через withHeader()/withoutHeader().
 */
final class SignedWebhook
{
    /** @param array<string, string> $headers имена заголовков как есть (X-Webhook-Id) */
    private function __construct(
        private readonly string $rawBody,
        private readonly int $timestamp,
        private readonly string $signature,
        private readonly array $headers,
    ) {}

    /**
     * Подписать сырые байты тела (не нормализовать — T1/§3.1).
     *
     * @param int|null $timestamp null — текущее замороженное время (travelTo)
     * @param string|null $secret null — config('marketplace.webhook_secret')
     */
    public static function forBody(string $rawBody, ?int $timestamp = null, ?string $secret = null): self
    {
        $timestamp ??= CarbonImmutable::now()->getTimestamp();

        // Пустой секрет — ошибка тестовой инфраструктуры, а не «подпись
        // пустым ключом»: config по умолчанию пуст (fail closed, Q11),
        // тест обязан задать секрет явно или через config().
        $secret ??= config('marketplace.webhook_secret');
        if (!is_string($secret) || $secret === '') {
            throw new \LogicException('Секрет вебхука не задан: укажите его аргументом или config(marketplace.webhook_secret).');
        }

        $signature = WebhookSignature::sign($rawBody, $timestamp, $secret);

        $headers = [
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Signature' => WebhookSignature::HEADER_PREFIX . $signature,
        ];

        $eventId = self::eventIdOf($rawBody);
        if ($eventId !== null) {
            $headers['X-Webhook-Id'] = $eventId;
        }

        return new self($rawBody, $timestamp, $signature, $headers);
    }

    /** Сокращение: подписать пример из task/examples как есть, байт в байт. */
    public static function forExample(string $example, ?int $timestamp = null, ?string $secret = null): self
    {
        return self::forBody(MarketplaceExamples::raw($example), $timestamp, $secret);
    }

    /**
     * Подменить заголовок (негативные сценарии: S-13/S-16 — кривая
     * подпись или несовпадающий X-Webhook-Id). Имя сопоставляется
     * без учёта регистра: безHeader('x-webhook-signature') удаляет
     * X-Webhook-Signature.
     */
    public function withHeader(string $name, string $value): self
    {
        $headers = $this->withoutHeaderKey($name);
        $headers[$name] = $value;

        return new self($this->rawBody, $this->timestamp, $this->signature, $headers);
    }

    /** Удалить заголовок (S-15 — отсутствие обязательного), без учёта регистра имени. */
    public function withoutHeader(string $name): self
    {
        return new self($this->rawBody, $this->timestamp, $this->signature, $this->withoutHeaderKey($name));
    }

    /** Сырое тело байт в байт (аргумент $content для call()). */
    public function content(): string
    {
        return $this->rawBody;
    }

    /** Timestamp подписи (для проверок окна ±300 с). */
    public function timestamp(): int
    {
        return $this->timestamp;
    }

    /** Вычисленная подпись без префикса — 64 hex, нижний регистр. */
    public function signature(): string
    {
        return $this->signature;
    }

    /** @return array<string, string> заголовки как есть */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Server-массив для MakesHttpRequests::call(): HTTP_* ключи
     * заголовков + Content-Type (send-event.php шлёт application/json)
     * и Content-Length фактического размера тела — как в реальном
     * HTTP-запросе (Symfony не синтезирует его из $content; важен
     * будет для проверок лимита 1 MiB по FR-WH-12 в T-017).
     *
     * @return array<string, string>
     */
    public function server(): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($this->rawBody),
        ];

        foreach ($this->headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }

    /**
     * `body.id` для X-Webhook-Id (send-event.php). Тело без читаемого
     * id (не JSON, нет поля, не строка) — null: заголовок опускается.
     */
    private static function eventIdOf(string $rawBody): ?string
    {
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded) || !is_string($decoded['id'] ?? null)) {
            return null;
        }

        return $decoded['id'];
    }

    /**
     * Заголовки без указанного имени; сопоставление имени — без учёта
     * регистра (защита от «молчаливого no-op» при другом написании).
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function withoutHeaderKey(string $name): array
    {
        $headers = $this->headers;

        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }
}
