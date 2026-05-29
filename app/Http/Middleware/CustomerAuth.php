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

        $request->setUserResolver(fn () => $customer);

        return $next($request);
    }
}
