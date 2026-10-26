<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MarketplaceWebhookController;
use App\Http\Middleware\VerifyMarketplaceSignature;
use Illuminate\Support\Facades\Route;

// Вебхук MarketHub (§6.1 ТЗ): api-группа не содержит CSRF и throttle
// (FR-WH-11, Q32); ответы api/* — всегда JSON (shouldRenderJsonWhen
// в bootstrap/app.php, FR-API-09).
Route::post('/webhooks/marketplace', MarketplaceWebhookController::class)
    ->middleware(VerifyMarketplaceSignature::class)
    ->name('marketplace.webhook');
