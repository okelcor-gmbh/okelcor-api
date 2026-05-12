<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any Sanctum token that does not belong to an AdminUser.
 *
 * Must run after auth:sanctum (which resolves $request->user()). Placing both
 * in the same middleware array guarantees the order:
 *   auth:sanctum → EnsureAdminToken → controller / admin.role
 *
 * Customer tokens pass auth:sanctum but fail here with 403, preventing them
 * from reaching any /admin/* controller — including the unguarded ones
 * (dashboard, me, profile, logout) that have no admin.role middleware.
 */
class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof AdminUser)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
