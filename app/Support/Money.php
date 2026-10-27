<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Денежные операции над суммами MarketHub (NFR-02, T-022).
 *
 * Все суммы — строки с двумя знаками после точки (`"1490.00"`); float
 * в денежных расчётах запрещён. Единая точка денежной арифметики:
 * карточка API (`items[].subtotal`) и warning-проверка `total` снимка
 * (FR-WH-09) считают здесь; состав писем §6.5 подключит тот же
 * хелпер в T-033 — своего умножения в письмах не появится.
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
     * Произведение BigDecimal точно и произвольной разрядности: даже
     * 99 999 999.99 × 99 = 9 899 999 999.01 не теряет копейки на
     * границе DECIMAL(12,2) (§6.3), где float даёт 9899999999.009999…
     *
     * Формат входа (строка с двумя знаками) — предпосылка источников
     * (парсер — формат суммы FR-WH-08, БД — каст decimal:2), а не
     * повторная валидация здесь: тип параметра — string, мусорный
     * вход громко падает NumberFormatException от BigDecimal::of, а
     * toScale(2) с режимом по умолчанию (UNNECESSARY) бросает
     * RoundingNecessaryException вместо молчаливого округления —
     * фиктивных сумм хелпер не возвращает.
     *
     * @param string $price цена за единицу, строка с двумя знаками
     */
    public static function subtotal(string $price, int $quantity): string
    {
        return (string) BigDecimal::of($price)
            ->multipliedBy($quantity)
            ->toScale(2);
    }
}
