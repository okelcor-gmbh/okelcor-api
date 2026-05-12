<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all proxies — required on Hostinger (nginx reverse proxy)
        // so Laravel sees HTTPS, correct client IPs, and correct APP_URL
        $middleware->trustProxies(at: '*');

        // CORS — applied globally so preflight OPTIONS requests are handled
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // ForceJsonResponse — prepended to every API route
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Role-based access control alias for admin routes
        $middleware->alias([
            'admin.role'    => \App\Http\Middleware\CheckAdminRole::class,
            'auth.customer' => \App\Http\Middleware\CustomerAuth::class,
            'auth.admin'    => \App\Http\Middleware\EnsureAdminToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
