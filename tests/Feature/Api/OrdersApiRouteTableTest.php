<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Маршрутная таблица (T-011, FR-API-11): публичные api-эндпоинты —
 * ровно контракт task/openapi.yaml (список и карточка заказов + вебхук
 * из T-010), других нет; служебный health endpoint GET /up отвечает.
 */
class OrdersApiRouteTableTest extends TestCase
{
    public function test_api_surface_matches_contract_exactly(): void
    {
        $apiRoutes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $apiRoutes[] = $method . ' ' . $route->uri();
                }
            }
        }

        sort($apiRoutes);

        self::assertSame(
            [
                'GET api/orders',
                'GET api/orders/{id}',
                'POST api/webhooks/marketplace',
            ],
            $apiRoutes,
        );
    }

    public function test_health_endpoint_answers(): void
    {
        $this->get('/up')->assertStatus(200);
    }
}
