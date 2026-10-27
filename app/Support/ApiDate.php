<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Формат дат API заказов (FR-API-06, A-21).
 *
 * ISO 8601 в UTC без долей секунды: `2026-10-22T06:40:03+00:00`.
 * Единственное место формата для элемента списка (OrderListItemResource)
 * и карточки (OrderResource): раньше две приватные копии formatDate()
 * могли разъехаться (аудит P3.7, правило дедупликации скилла
 * laravel-best-practices). Формат пиннится контрактными API-тестами
 * (OrderShowApiTest/OrderShowContractApiTest).
 */
final class ApiDate
{
    /**
     * Дата ISO 8601 в UTC без долей секунды; null проходит насквозь —
     * nullable-поля контракта присутствуют в ответе всегда (как null).
     */
    public static function format(?CarbonImmutable $date): ?string
    {
        return $date?->utc()->format('Y-m-d\TH:i:sP');
    }
}
