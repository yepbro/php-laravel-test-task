<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketplaceWebhookController;
use App\Http\Controllers\Api\Orders\IndexController;
use App\Http\Controllers\Api\Orders\ShowController;
use App\Http\Middleware\LimitMarketplaceWebhookBody;
use App\Http\Middleware\VerifyMarketplaceSignature;
use Illuminate\Support\Facades\Route;

// Вебхук MarketHub (§6.1 ТЗ): api-группа не содержит CSRF и throttle
// (FR-WH-11, Q32); ответы api/* — всегда JSON (shouldRenderJsonWhen
// в bootstrap/app.php, FR-API-09). Порядок middleware — по §7: сначала
// лимит тела 1 MiB (шаг 1, FR-WH-12), затем проверка подписи (шаги 2–4).
Route::post('/webhooks/marketplace', MarketplaceWebhookController::class)
    ->middleware([
        LimitMarketplaceWebhookBody::class,
        VerifyMarketplaceSignature::class,
    ])
    ->name('marketplace.webhook');

// API заказов для админки (§6.6 ТЗ, контракт task/openapi.yaml):
// только эндпоинты контракта, других публичных не добавлять
// (FR-API-11). Health endpoint GET /up регистрируется отдельной
// опцией health в bootstrap/app.php — там же, где api-группа.
// Контроллеры — invokable, по одному на операцию (правило routing
// скилла laravel-best-practices; единый паттерн с вебхуком).
// Параметр {order} — implicit binding по строковому ключу MarketHub:
// имя параметра внутреннее, URL-контракт /api/orders/{id} не меняется.
Route::get('/orders', IndexController::class)->name('orders.index');
Route::get('/orders/{order}', ShowController::class)->name('orders.show');
