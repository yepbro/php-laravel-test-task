<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketplaceWebhookController;
use App\Http\Controllers\Api\Orders\IndexController;
use App\Http\Controllers\Api\Orders\ShowController;
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
// Контроллеры — invokable, по одному на операцию (правило routing
// скилла laravel-best-practices; единый паттерн с вебхуком).
Route::get('/orders', IndexController::class)->name('orders.index');
Route::get('/orders/{id}', ShowController::class)->name('orders.show');
