<?php

declare(strict_types=1);

namespace App\Http\Queries;

use App\Data\OrderListParameters;
use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * Выборка заказов для API (§6.6 ТЗ, скелет T-011).
 *
 * Единственный потребитель — контроллер API заказов: админка читает
 * данные тем же GET /api/orders (Q3-гибрид, FR-ADM-05), а не этим
 * классом напрямую.
 *
 * Начальная честная версия: сортировка placed_at DESC, id DESC
 * (FR-API-01 — дата оформления маркетплейса, а не системная created_at
 * записи), фильтр status (FR-API-02), пагинация со стандартной
 * обёрткой data/links/meta и сохранением query string в ссылках
 * (FR-API-03). Фильтр email (trim + lower, точное совпадение по
 * customer_email_normalized) и краевые сценарии выборки — задача
 * T-036: до неё запрос с непустым email отказывает LogicException'ом,
 * а не возвращает неотфильтрованный список, изображая фильтрацию
 * (правило этапа 2 — без фиктивного успеха).
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
        if ($parameters->email !== null) {
            throw new LogicException('Фильтр email ещё не реализован (задача T-036).');
        }

        $query = $this->order->newQuery()
            ->orderByDesc('placed_at')
            ->orderByDesc('id');

        if ($parameters->status !== null) {
            $query->where('status', $parameters->status->value);
        }

        /** @var LengthAwarePaginator<int, Order> */
        $paginator = $query->paginate(
            perPage: $parameters->perPage,
            page: $parameters->page,
        );

        return $paginator->withQueryString();
    }
}
