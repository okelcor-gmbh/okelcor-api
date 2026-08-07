<?php

namespace App\Http\Middleware;

use App\Models\PartnerUser;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner app authentication.
 *
 * Mirrors CustomerAuth exactly — a middleware resolving a Sanctum personal
 * access token, not a guard. `config/auth.php` defines only `web` (unused)
 * and `admin`; customer auth has always been a middleware here, and partner
 * auth being a third of the same shape keeps one pattern rather than adding a
 * parallel one.
 *
 * The isolation that matters is the `tokenable_type` check: a customer token
 * or an admin token presented to a /partner route is rejected outright, so
 * three separate user classes can share one token table without any of them
 * being able to act as another.
 */
class PartnerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if (! $rawToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($rawToken);

        if (! $accessToken || $accessToken->tokenable_type !== PartnerUser::class) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Expiry enforced on use rather than by a scheduled sweep — nothing
        // guarantees a cleanup command runs on this host.
        $ttlDays = (int) config('partner.token_ttl_days', 90);
        if ($ttlDays > 0 && $accessToken->created_at?->addDays($ttlDays)->isPast()) {
            $accessToken->delete();

            return response()->json([
                'message' => 'Session expired. Please sign in again.',
                'code'    => 'token_expired',
            ], 401);
        }

        /** @var PartnerUser|null $partnerUser */
        $partnerUser = $accessToken->tokenable;

        if (! $partnerUser || ! $partnerUser->is_active) {
            return response()->json([
                'message' => 'This account is no longer active.',
                'code'    => 'user_inactive',
            ], 401);
        }

        $organisation = $partnerUser->organisation;

        if (! $organisation || ! $organisation->isActive()) {
            return response()->json([
                'message' => 'This partner account has been suspended. Please contact Okelcor.',
                'code'    => 'org_suspended',
            ], 403);
        }

        // A locked account must not keep working on an already-issued token —
        // otherwise lockout only stops new logins, which on a shared device is
        // the wrong half of the problem.
        if ($partnerUser->isLocked()) {
            return response()->json([
                'message' => 'This account is temporarily locked. Try again later.',
                'code'    => 'account_locked',
            ], 403);
        }

        $request->setUserResolver(fn () => $partnerUser);

        return $next($request);
    }
}
