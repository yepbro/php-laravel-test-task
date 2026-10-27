<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * API заказов для админки (§6.6 ТЗ, скелет T-011): GET /api/orders/{id} —
 * карточка. Единственный потребитель — браузер админки (Q3-гибрид,
 * FR-ADM-05).
 *
 * Тонкий HTTP-слой (NFR-08): выборка карточки — eager loading без N+1,
 * сериализация целиком — OrderResource.
 */
final class ShowController extends Controller
{
    /**
     * Карточка заказа (FR-API-05): позиции и история — eager loading
     * без N+1; позиции упорядочены по position, порядок истории задаёт
     * OrderResource по FR-HIS-04 (at, ранг статуса, id). Несуществующий
     * id — abort(404), который для api/* рендерится JSON (FR-API-09).
     */
    public function __invoke(string $id): OrderResource
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->with([
                'items' => fn(HasMany $items) => $items->orderBy('position'),
                'history',
            ])
            ->find($id);

        if (! $order instanceof Order) {
            abort(404);
        }

        return new OrderResource($order);
    }
}
