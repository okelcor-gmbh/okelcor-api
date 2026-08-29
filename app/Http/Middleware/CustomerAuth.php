<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CustomerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if (! $rawToken) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($rawToken);

        if (
            ! $accessToken ||
            $accessToken->tokenable_type !== Customer::class
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Expiry (Session 104). Sanctum's own Guard checks expires_at, but
        // this middleware resolves the token itself and never went through
        // the Guard — so before this check, a TTL stamped onto a customer
        // token was recorded and never enforced. Admin routes use
        // auth:sanctum and were always covered.
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $customer = $accessToken->tokenable;

        if (! $customer || ! $customer->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 401);
        }

        // Onboarding gate — block access for accounts not yet fully active
        $onboardingStatus = $customer->onboarding_status ?? 'active';
        if (in_array($onboardingStatus, ['pending_review', 'rejected', 'blocked'], true)) {
            return response()->json([
                'message'           => 'Account access not yet granted.',
                'onboarding_status' => $onboardingStatus,
            ], 403);
        }

        // Access level gate — fully blocked customers cannot use any authenticated endpoint
        if (($customer->access_level ?? 'inquiry_only') === 'blocked') {
            return response()->json([
                'message'      => 'Your account access has been restricted. Please contact Okelcor.',
                'code'         => 'access_blocked',
                'access_level' => 'blocked',
            ], 403);
        }

        // Attach the token to the model so currentAccessToken() answers
        // (Session 104). It never was, so `$request->user()
        // ->currentAccessToken()` returned null on every customer route —
        // which means POST /auth/logout has thrown on the null the whole
        // time, and the password-change path could not tell "this session"
        // from the others it revokes.
        $customer->withAccessToken($accessToken);

        $request->setUserResolver(fn () => $customer);

        return $next($request);
    }
}
