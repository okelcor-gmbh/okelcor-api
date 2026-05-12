<?php

namespace App\Http\Middleware;

use App\Services\AdminAuditLogger;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes:
 *   ->middleware('permission:orders.update')
 *
 * Derives access from AdminPermissions::MAP — never from raw role strings.
 * Logs all denied attempts on sensitive endpoints for audit trail.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user && AdminPermissions::can($user->role, $permission)) {
            return $next($request);
        }

        AdminAuditLogger::warning('permission_denied', "Permission denied: {$permission} on {$request->method()} /{$request->path()}", $request, $user, [
            'permission_attempted' => $permission,
            'method'               => $request->method(),
            'path'                 => $request->path(),
        ]);

        return response()->json([
            'message'    => 'Forbidden. You do not have permission to perform this action.',
            'permission' => $permission,
        ], 403);
    }
}
