<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use App\Http\Middleware\TrustProxies;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // TrustProxies is required for Railway's reverse proxy to work correctly
        $middleware->prepend(TrustProxies::class);

        // HandleCors MUST be in the global middleware stack so it can
        // intercept OPTIONS preflight requests before route matching.
        // Without this, browsers block all cross-origin requests.
        $middleware->prepend(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
