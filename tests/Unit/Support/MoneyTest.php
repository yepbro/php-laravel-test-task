<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Расчёт стоимости позиции заказа (T-016; красное до T-022).
 *
 * §6.3 ТЗ: `subtotal = price × quantity` через Brick\Math BigDecimal —
 * float в денежных расчётах запрещён (NFR-02), результат — строка
 * ровно с двумя знаками после точки. Значения — из примеров
 * task/examples и письма §6.5 (3 × 1290.00 = 3870.00).
 *
 * Проверка арифметики `total` снимка — НЕ здесь (FR-WH-09: расхождение
 * — только warning). Пока Money::subtotal() — заглушка T-022, все тесты
 * падают с её LogicException; после реализации становятся зелёными
 * без правки тестов.
 */
class MoneyTest extends TestCase
{
    /**
     * Табличные случаи: произведение всегда строкой с двумя знаками,
     * quantity = 1 возвращает цену дословно (без «890.5»/«890.50 RUB»).
     *
     * @param non-empty-string $price
     * @param non-empty-string $expected
     */
    #[DataProvider('subtotalProvider')]
    public function test_subtotal_multiplies_price_by_quantity(string $price, int $quantity, string $expected): void
    {
        $this->assertSame($expected, Money::subtotal($price, $quantity));
    }

    /**
     * @return iterable<string, array{non-empty-string, int, non-empty-string}>
     */
    public static function subtotalProvider(): iterable
    {
        return [
            'футболка из письма §6.5 (order-shipped: 3 шт.)' => ['1290.00', 3, '3870.00'],

            'бейсболка из письма §6.5 (order-created/updated: 1 шт.)' => ['890.50', 1, '890.50'],

            'футболка до изменения состава (order-created: 2 шт.)' => ['1290.00', 2, '2580.00'],

            'минимальная цена' => ['0.01', 3, '0.03'],

            'нулевая цена' => ['0.00', 5, '0.00'],

            'круглое произведение — всё равно два знака' => ['10.00', 2, '20.00'],
        ];
    }

    /**
     * Граница точности DECIMAL(12,2): 99 999 999.99 × 99 — float даёт
     * 9899999999.009999… и теряет копейки; BigDecimal — точный результат
     * в пределах разрядной сетки колонки (§6.3).
     */
    public function test_subtotal_keeps_precision_without_float_on_large_amounts(): void
    {
        $this->assertSame('9899999999.01', Money::subtotal('99999999.99', 99));
    }

    /**
     * Формат результата — денежная строка с ровно двумя знаками после
     * точки (§6.5: суммы выводятся дословно + код валюты; API FR-API-05:
     * `items[].subtotal`). Не «3870.0», не «3 870.00», не float.
     */
    #[DataProvider('subtotalProvider')]
    public function test_subtotal_result_is_decimal_string_with_two_places(string $price, int $quantity): void
    {
        $result = Money::subtotal($price, $quantity);

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $result);
    }
}
