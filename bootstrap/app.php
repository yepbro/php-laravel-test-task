<?php

declare(strict_types=1);

use App\Exceptions\Webhook\WebhookException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Responses\WebhookResponseMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Единый HTTP-вид исключений вебхука (T-010): бросаются как
        // middleware подписи (до контроллера), так и Action обработки,
        // поэтому точка рендера одна — WebhookResponseMapper. 401 едино
        // для всех причин отказа аутентификации (NFR-04), 400/422 —
        // по типизированной PayloadIssue.
        $exceptions->render(
            fn(WebhookException $exception, Request $request): JsonResponse
                => WebhookResponseMapper::fromException($exception),
        );
    })->create();
