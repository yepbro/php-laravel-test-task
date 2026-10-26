<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OrderStatus;

/**
 * Валидированные параметры выборки списка заказов для API (§6.6 ТЗ, T-011).
 *
 * Переносит фильтры и пагинацию одним типизированным объектом от
 * ListOrdersRequest к OrderListQuery; значения уже проверены правилами
 * запроса (status — case enum'а, page ≥ 1, per_page 1..100), дефолты
 * пагинации (page = 1, per_page = 20, FR-API-03) подставляет
 * ListOrdersRequest::orderListParameters() — единственное место
 * конструирования DTO.
 */
final readonly class OrderListParameters
{
    public function __construct(
        public ?OrderStatus $status,
        public ?string $email,
        public int $page,
        public int $perPage,
    ) {}
}
