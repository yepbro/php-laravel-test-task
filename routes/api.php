<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketplaceWebhookController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Middleware\VerifyMarketplaceSignature;
use Illuminate\Support\Facades\Route;

// Вебхук MarketHub (§6.1 ТЗ): api-группа не содержит CSRF и throttle
// (FR-WH-11, Q32); ответы api/* — всегда JSON (shouldRenderJsonWhen
// в bootstrap/app.php, FR-API-09).
Route::post('/webhooks/marketplace', MarketplaceWebhookController::class)
    ->middleware(VerifyMarketplaceSignature::class)
    ->name('marketplace.webhook');

// API заказов для админки (§6.6 ТЗ, контракт task/openapi.yaml):
// только эндпоинты контракта, других публичных не добавлять
// (FR-API-11). Health endpoint GET /up регистрируется отдельной
// опцией health в bootstrap/app.php — там же, где api-группа.
Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
Route::get('/orders/{id}', [OrdersController::class, 'show'])->name('orders.show');
