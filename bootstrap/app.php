<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\RecordApiMetrics;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Without the web group: they use no session, so they still answer when the database is down.
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');
            Route::view('/metrics', 'metrics')->name('metrics');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global (and first), so it also sees /api requests no route matches (404, 405) and the
        // measured duration covers the whole pipeline; it only acts on /api paths.
        $middleware->prepend(RecordApiMetrics::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
