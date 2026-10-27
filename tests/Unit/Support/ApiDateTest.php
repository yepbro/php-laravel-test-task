<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ApiDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Формат дат API заказов (T-037; хелпер выделен в T-011 по аудиту P3.7).
 *
 * FR-API-06/A-21: ISO 8601, UTC `+00:00`, без долей секунды, момент
 * совпадает с данными маркетплейса. HTTP-тесты пиннят точные строки
 * для дат из БД, но MySQL DATETIME хранит UTC без смещения и дробей —
 * края формата через эндпоинты недостижимы, поэтому контракт хелпера
 * (единственная точка формата для обоих ресурсов) зафиксирован прямым
 * unit-набором: null-проход для nullable-полей, отбрасывание
 * микросекунд, приведение ненулевого смещения к тому же моменту UTC.
 */
class ApiDateTest extends TestCase
{
    /**
     * Nullable-поля контракта присутствуют в ответе всегда (FR-API-05)
     * — null проходит насквозь, а не исчезает из ответа.
     */
    public function test_null_passes_through_for_nullable_contract_fields(): void
    {
        self::assertNull(ApiDate::format(null));
    }

    /**
     * @param non-empty-string $input
     * @param non-empty-string $expected
     */
    #[DataProvider('utcFormatProvider')]
    public function test_formats_as_utc_without_fractional_seconds(string $input, string $expected): void
    {
        self::assertSame($expected, ApiDate::format(CarbonImmutable::parse($input)));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function utcFormatProvider(): iterable
    {
        return [
            'UTC-вход — формат A-21 дословно' => [
                '2026-10-22T06:40:03+00:00',
                '2026-10-22T06:40:03+00:00',
            ],

            'микросекунды входа отбрасываются' => [
                '2026-10-22T06:40:03.987654+00:00',
                '2026-10-22T06:40:03+00:00',
            ],

            'положительное смещение — тот же момент в UTC' => [
                '2026-10-21T13:05:47+03:00',
                '2026-10-21T10:05:47+00:00',
            ],

            'отрицательное смещение — тот же момент в UTC' => [
                '2026-10-21T02:40:03-04:00',
                '2026-10-21T06:40:03+00:00',
            ],
        ];
    }
}
