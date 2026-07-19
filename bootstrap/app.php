<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Live chat (Pusher) — auth'd the same way every other API route is
    // (Sanctum bearer token, not session/web), and placed under the same
    // api/v1 prefix rather than the framework's default /broadcasting/auth
    // at web root. Works for BOTH guards (AdminUser and Customer both use
    // Sanctum) since routes/channels.php type-checks $user itself.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum'], 'prefix' => 'api/v1'],
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
            'admin.role'       => \App\Http\Middleware\CheckAdminRole::class,
            'auth.customer'    => \App\Http\Middleware\CustomerAuth::class,
            'auth.admin'       => \App\Http\Middleware\EnsureAdminToken::class,
            'ensure.admin.2fa' => \App\Http\Middleware\EnsureAdminTwoFactorEnabled::class,
            'permission'       => \App\Http\Middleware\CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Structured log for every 429 produced by throttle middleware.
        // Registered first so it runs before the general reporter below.
        $exceptions->report(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e) {
            /** @var \Illuminate\Http\Request $request */
            $request = request();
            Log::warning('[rate_limit_exceeded]', [
                'route'   => $request->route()?->getName() ?? $request->path(),
                'method'  => $request->method(),
                'ip'      => $request->ip(),
                'user_id' => $request->user()?->id ?? null,
            ]);
            return false; // suppress — not an application error
        });

        // Log all unhandled exceptions with structured context (request-id, route, user)
        $exceptions->report(function (\Throwable $e) {
            // Skip validation / auth / rate-limit exceptions — already handled with HTTP 4xx
            if (
                $e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Illuminate\Auth\AuthenticationException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                $e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
            ) {
                return false; // suppress — not worth a CRITICAL log entry
            }

            /** @var Request|null $request */
            $request = request();

            $user    = $request?->user();
            $userId  = $user?->id ?? 'guest';
            $route   = $request?->route()?->getName()
                    ?? $request?->path()
                    ?? 'unknown';

            Log::critical('[unhandled_exception] ' . $e->getMessage(), [
                'exception'  => get_class($e),
                'file'       => $e->getFile() . ':' . $e->getLine(),
                'route'      => $route,
                'method'     => $request?->method() ?? 'CLI',
                'url'        => $request?->fullUrl() ?? 'CLI',
                'ip'         => $request?->ip() ?? '127.0.0.1',
                'user_id'    => $userId,
                'request_id' => $request?->header('X-Request-Id') ?? uniqid('req_'),
            ]);
        });
    })->create();
