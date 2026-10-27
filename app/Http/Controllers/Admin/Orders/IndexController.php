<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListOrdersRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia-оболочка списка заказов админки (§6.7 ТЗ, Q3-гибрид,
 * FR-ADM-05): маршрут, layout и навигацию ведёт Inertia, а данные
 * заказов браузер запрашивает у контракта task/openapi.yaml
 * (GET /api/orders). Поэтому контроллер НЕ выбирает заказы и НЕ
 * передаёт их через props — страница получает только вспомогательные
 * данные, которых нет в контракте: опции фильтра статусов с русскими
 * подписями и дефолтный per_page (для инициализации клиентского
 * запроса списка).
 *
 * Полная серверная часть фильтров/пагинации — T-039, богатый UI —
 * T-040/T-041; здесь только скелет (T-013).
 */
final class IndexController extends Controller
{
    public function __invoke(): Response
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
