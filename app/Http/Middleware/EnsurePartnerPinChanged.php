<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block a partner user from doing anything except change their PIN, until they
 * have changed the one an admin set for them.
 *
 * A partner account is created by Okelcor admin with a starting PIN, so that
 * PIN is known to at least one other person by construction. Against a threat
 * model that explicitly includes shared devices, an admin-chosen PIN that never
 * expires is the compromise the PIN exists to prevent.
 *
 * The client also gates this, but a client-side gate is a courtesy rather than
 * a control — the same principle already applied to the partner edit window,
 * which is enforced against the server clock rather than the device's.
 *
 * Deliberately mirrors EnsureAdminTwoFactorEnabled, including its 428 status
 * ("Precondition Required"), so the two mandatory-setup gates in this codebase
 * behave identically rather than each inventing a convention.
 *
 * Always allows the endpoints needed to satisfy or exit the gate:
 *   - GET  /partner/me              (the client routes off `must_change_pin`)
 *   - POST /partner/auth/change-pin (the way out)
 *   - POST /partner/auth/logout     (never trap someone in a session)
 */
class EnsurePartnerPinChanged
{
    private const ALLOWED_PATHS = [
        'api/v1/partner/me',
        'api/v1/partner/auth/change-pin',
        'api/v1/partner/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_pin) {
            return $next($request);
        }

        if (in_array(ltrim($request->path(), '/'), self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Please choose your own PIN before you start logging sales.',
            'code'    => 'pin_change_required',
        ], 428);
    }
}
