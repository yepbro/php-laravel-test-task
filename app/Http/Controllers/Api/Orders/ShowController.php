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
 * Тонкий HTTP-слой (NFR-08): заказ разрешается implicit route model
 * binding'ом по строковому ключу MarketHub (правило routing скилла
 * laravel-best-practices — без ручного find()/abort(404); несуществующий
 * id даёт ModelNotFoundException → стандартный 404, который для api/*
 * рендерится JSON, FR-API-09). Сериализация целиком — OrderResource.
 */
final class ShowController extends Controller
{
    /**
     * Карточка заказа (FR-API-05): позиции и история — eager loading
     * без N+1 (loadMissing: строгий режим моделей запрещает ленивую
     * загрузку); позиции упорядочены по position, порядок истории задаёт
     * OrderResource по FR-HIS-04 (at, ранг статуса, id).
     */
    public function __invoke(Order $order): OrderResource
    {
        $order->loadMissing([
            'items' => fn(HasMany $items) => $items->orderBy('position'),
            'history',
        ]);

        return new OrderResource($order);
    }
}
