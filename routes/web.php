<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\OrdersController;
use Illuminate\Support\Facades\Route;

// Админка (§6.7 ТЗ, Q3-гибрид): `/` перенаправляет на список заказов
// (FR-ADM-01), сами страницы — Inertia-оболочки; данные заказов браузер
// запрашивает у методов /api/orders (task/openapi.yaml), а не через
// props. Полные маршруты админки: список и карточка (FR-ADM-01/02);
// страница 404 для несуществующих маршрутов — стандартный handler
// Laravel (FR-ADM-04).
Route::redirect('/', '/admin/orders')->name('home');

Route::get('/admin/orders', [OrdersController::class, 'index'])
    ->name('admin.orders.index');

Route::get('/admin/orders/{id}', [OrdersController::class, 'show'])
    ->name('admin.orders.show');
