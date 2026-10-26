<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Денежные операции над суммами MarketHub (NFR-02, скелет T-009).
 *
 * Все суммы — строки с двумя знаками после точки (`"1490.00"`); float
 * в денежных расчётах запрещён. Реализация (BigDecimal, округление,
 * крайние случаи) — задача T-022; тесты — T-016.
 */
final class Money
{
    /**
     * Стоимость позиции: price × quantity, строка с двумя знаками.
     *
     * Используется для `items[].subtotal` в API (FR-API-05/FR-API-06)
     * и состава письма (§6.5). Проверка арифметики `total` снимка —
     * не здесь (FR-WH-09: расхождение — только warning).
     *
     * @param non-empty-string $price цена за единицу, строка с двумя знаками
     *
     * @throws \LogicException пока не реализовано (задача T-022)
     */
    public static function subtotal(string $price, int $quantity): string
    {
        throw new \LogicException('Расчёт subtotal ещё не реализован (задача T-022).');
    }
}
