<?php

declare(strict_types=1);

namespace App\Http\Queries;

use App\Data\OrderListParameters;
use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Выборка заказов для API (§6.6 ТЗ; скелет T-011, фильтр email — T-036).
 *
 * Единственный потребитель — контроллер API заказов: админка читает
 * данные тем же GET /api/orders (Q3-гибрид, FR-ADM-05), а не этим
 * классом напрямую.
 *
 * Сортировка placed_at DESC, id DESC (FR-API-01 — дата оформления
 * маркетплейса, а не системная created_at записи), фильтры status
 * и email (FR-API-02: email приходит уже нормализованным trim + lower
 * из ListOrdersRequest и сравнивается точным совпадением по
 * `customer_email_normalized` — покрывает индекс
 * `(customer_email_normalized, placed_at)`), пагинация со стандартной
 * обёрткой data/links/meta и сохранением query string в ссылках
 * (FR-API-03).
 */
final class OrderListQuery
{
    public function __construct(
        private readonly Order $order,
    ) {}

    /**
     * Пагинированный список заказов по правилам §6.6 ТЗ.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(OrderListParameters $parameters): LengthAwarePaginator
    {
        $query = $this->order->newQuery()
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($parameters->status !== null) {
            $query->where('status', $parameters->status->value);
        }

        if ($parameters->email !== null) {
            $query->where('customer_email_normalized', $parameters->email);
        }

        /** @var LengthAwarePaginator<int, Order> */
        $paginator = $query->paginate(
            perPage: $parameters->perPage,
            page: $parameters->page,
        );

        return $paginator->withQueryString();
    }
}
