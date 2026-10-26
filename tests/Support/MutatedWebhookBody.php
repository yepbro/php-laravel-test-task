<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Билдер мутантных тел событий для тестов (T-015): неизвестный
 * `type`/`status`, управляемый `data.order.updated_at` и другие
 * контролируемые изменения примеров.
 *
 * Принцип (зафиксирован в DECISIONS.md, задача 015): сырые примеры
 * `task/examples/*.json` тесты отправляют байт в байт
 * (MarketplaceExamples::raw + SignedWebhook), а мутанты строятся
 * честной пересериализацией — json_decode → изменение → json_encode
 * c JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES (кириллица и `//`
 * не экранируются, как в примерах MarketHub). Мутант по построению
 * НЕ обязан совпадать с примером байт в байт — это другой документ,
 * и подписывается он как есть, по собственным байтам.
 */
final class MutatedWebhookBody
{
    /**
     * Декодированное тело (ассоциативный массив); PHP-массивы —
     * значения с copy-on-write, поэтому with*-методы копируют
     * данные автоматически, глубокое клонирование не нужно.
     *
     * @var array<string, mixed>
     */
    private readonly array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /** Мутант на основе сырого примера (MarketplaceExamples::raw). */
    public static function fromExample(string $example): self
    {
        return self::fromRaw(MarketplaceExamples::raw($example));
    }

    /** Мутант на основе произвольного JSON-тела (строкой). */
    public static function fromRaw(string $rawBody): self
    {
        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Тело мутанта должно быть валидным JSON: ' . $exception->getMessage(), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Тело мутанта должно быть JSON-объектом.');
        }

        return new self($decoded);
    }

    /** Неизвестный тип события (S-18: `order.refunded` и т.п.). */
    public function withType(string $type): self
    {
        return $this->with('type', $type);
    }

    /** Неизвестный статус в data.order.status (S-36: `returned` и т.п.). */
    public function withStatus(string $status): self
    {
        return $this->withPath(['data', 'order', 'status'], $status);
    }

    /** Управляемый data.order.updated_at (версия снимка, FR-ORD-02). */
    public function withUpdatedAt(string $iso8601): self
    {
        return $this->withPath(['data', 'order', 'updated_at'], $iso8601);
    }

    /** Значение корневого поля конверта. */
    public function with(string $key, mixed $value): self
    {
        return $this->withPath([$key], $value);
    }

    /**
     * Значение по пути вложенных полей; отсутствующий путь создаётся.
     * Билдер иммутабелен: with* возвращают новый экземпляр.
     *
     * @param non-empty-list<non-empty-string> $path
     */
    public function withPath(array $path, mixed $value): self
    {
        $data = $this->setByPath($this->data, $path, $value);

        return new self($data);
    }

    /** Тело мутанта как строку (для SignedWebhook::forBody). */
    public function body(): string
    {
        try {
            return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Не удалось закодировать тело мутанта: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param non-empty-list<non-empty-string> $path
     * @return array<string, mixed>
     */
    private function setByPath(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        assert($key !== null);

        if ($path === []) {
            $data[$key] = $value;

            return $data;
        }

        /** @var array<string, mixed> $nested */
        $nested = is_array($data[$key] ?? null) ? $data[$key] : [];
        $data[$key] = $this->setByPath($nested, $path, $value);

        return $data;
    }
}
