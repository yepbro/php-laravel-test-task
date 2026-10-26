<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\MarketplaceExamples;
use Tests\TestCase;

/**
 * T-015. Читатель сырых примеров task/examples/*.json: байты идут
 * в тесты без нормализации и пересериализации (§3.1 ТЗ), `task/`
 * не изменяется. Фиксируются фактические свойства файлов: размеры,
 * финальный `\n` у примеров событий и его отсутствие у эталонного
 * тела подписи, неэкранированная кириллица (S-22/S-23 опираются
 * на эти байты).
 */
class MarketplaceExamplesTest extends TestCase
{
    /** Размеры файлов из §3.1 ТЗ (пересчитаны по материалам). */
    private const int SIGNATURE_BODY_BYTES = 765;

    private const int ORDER_PAID_BYTES = 1349;

    public function test_raw_returns_file_bytes_exactly(): void
    {
        $raw = MarketplaceExamples::raw('order-paid');

        $this->assertSame(self::ORDER_PAID_BYTES, strlen($raw));
        $this->assertSame('{', $raw[0]);
    }

    /**
     * Примеры событий заканчиваются переводом строки (§3.1):
     * последний байт — именно `\n`, а не нормализованный JSON.
     */
    #[TestWith(['order-created'])]
    #[TestWith(['order-updated'])]
    #[TestWith(['order-paid'])]
    #[TestWith(['order-shipped'])]
    #[TestWith(['order-cancelled'])]
    public function test_event_examples_keep_trailing_newline(string $example): void
    {
        $raw = MarketplaceExamples::raw($example);

        $this->assertSame("\n", substr($raw, -1));
    }

    /**
     * Эталонное тело подписи — 765 байт БЕЗ перевода строки в конце
     * (signature-example.md): хелпер ничего не добавляет и не убирает.
     */
    public function test_signature_example_body_has_no_trailing_newline(): void
    {
        $raw = MarketplaceExamples::raw('signature-example-body');

        $this->assertSame(self::SIGNATURE_BODY_BYTES, strlen($raw));
        $this->assertSame('}', substr($raw, -1));
    }

    /** Кириллица в примерах неэкранирована — байты UTF-8, не `\u…` (S-22). */
    public function test_examples_keep_unescaped_utf8_cyrillic(): void
    {
        $raw = MarketplaceExamples::raw('order-paid');

        $this->assertStringContainsString('Анна Смирнова', $raw);
        $this->assertStringNotContainsString('\u0410', $raw);
    }

    /** Имя примера принимается с суффиксом .json и без него. */
    public function test_example_name_with_and_without_suffix(): void
    {
        $this->assertSame(
            MarketplaceExamples::raw('order-paid'),
            MarketplaceExamples::raw('order-paid.json'),
        );
    }

    /** Повторное чтение того же примера — те же байты (без побочных эффектов). */
    public function test_raw_is_stable_across_reads(): void
    {
        $this->assertSame(MarketplaceExamples::raw('order-paid'), MarketplaceExamples::raw('order-paid'));
    }

    public function test_unknown_example_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MarketplaceExamples::raw('order-missing');
    }
}
