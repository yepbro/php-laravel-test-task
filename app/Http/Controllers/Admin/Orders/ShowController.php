<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia-оболочка карточки заказа админки (§6.7 ТЗ, Q3-гибрид,
 * FR-ADM-05): страница получает только идентификатор из URL — карточку
 * браузер грузит клиентом у GET /api/orders/{id} (task/openapi.yaml),
 * 404 несуществующего заказа — клиентское состояние по ответу API
 * (FR-ADM-04), серверная страница-оболочка отвечает 200 всегда.
 * Поэтому тут сознательно нет ни выборки, ни route model binding —
 * заказы через props не передаются.
 *
 * Богатый UI карточки — T-041; здесь только скелет (T-013).
 */
final class ShowController extends Controller
{
    public function __invoke(string $id): Response
    {
        return Inertia::render('admin/orders/Show', [
            'id' => $id,
        ]);
    }
}
