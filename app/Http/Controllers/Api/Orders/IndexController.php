<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Queries\OrderListQuery;
use App\Http\Requests\Api\ListOrdersRequest;
use App\Http\Resources\OrderListItemResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API заказов для админки (§6.6 ТЗ, скелет T-011): GET /api/orders —
 * список. Единственный потребитель — браузер админки (Q3-гибрид,
 * FR-ADM-05).
 *
 * Тонкий HTTP-слой (NFR-08): валидация — в ListOrdersRequest, правила
 * выборки — в OrderListQuery, сериализация — в Resources; контроллер
 * только связывает их. Ответы api/* — всегда JSON независимо от Accept
 * (shouldRenderJsonWhen в bootstrap/app.php, FR-API-09; настроено
 * в T-010 и переиспользуется без изменений).
 */
final class IndexController extends Controller
{
    public function __construct(
        private readonly OrderListQuery $orders,
    ) {}

    /**
     * Список заказов: сортировка/фильтры/пагинация — OrderListQuery
     * (FR-API-01..03), формат элемента — OrderListItemResource
     * (FR-API-04), обёртка data/links/meta — стандартная пагинированная
     * коллекция API Resources (FR-API-03).
     */
    public function __invoke(ListOrdersRequest $request): AnonymousResourceCollection
    {
        return OrderListItemResource::collection(
            $this->orders->paginate($request->orderListParameters()),
        );
    }
}
