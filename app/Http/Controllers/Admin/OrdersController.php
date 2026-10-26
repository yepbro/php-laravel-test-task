<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListOrdersRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia-оболочки админки (§6.7 ТЗ, Q3-гибрид, FR-ADM-05): маршруты,
 * layout и навигацию ведёт Inertia, а данные заказов браузер запрашивает
 * у методов контракта task/openapi.yaml (GET /api/orders, GET
 * /api/orders/{id}). Поэтому контроллеры НЕ выбирают заказы и НЕ
 * передают их через props — страницы получают только вспомогательные
 * данные, которых нет в контракте:
 *
 * - index: опции фильтра статусов с русскими подписями и дефолтный
 *   per_page (для инициализации клиентского запроса списка);
 * - show: только идентификатор из URL — карточка загружается клиентом,
 *   404 несуществующего заказа — клиентское состояние по ответу API
 *   (FR-ADM-04), серверная страница-оболочка отвечает 200 всегда.
 *
 * Полная серверная часть фильтров/пагинации — T-039, богатый UI —
 * T-040/T-041; здесь только скелет (T-013).
 */
final class OrdersController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/orders/Index', [
            'statusOptions' => array_map(
                static fn(OrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => self::statusLabel($status),
                ],
                OrderStatus::cases(),
            ),
            'defaultPerPage' => ListOrdersRequest::DEFAULT_PER_PAGE,
        ]);
    }

    public function show(string $id): Response
    {
        return Inertia::render('admin/orders/Show', [
            'id' => $id,
        ]);
    }

    /**
     * Русская подпись статуса для фильтра списка (FR-ADM-05: подписи —
     * вспомогательные данные, в контракте API их нет). Полный набор
     * локализованных текстов статусов в UI — T-040/T-041.
     */
    private static function statusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::CREATED => 'Создан',
            OrderStatus::PAID => 'Оплачен',
            OrderStatus::SHIPPED => 'Отправлен',
            OrderStatus::DELIVERED => 'Доставлен',
            OrderStatus::CANCELLED => 'Отменён',
        };
    }
}
