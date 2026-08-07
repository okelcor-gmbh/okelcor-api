<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\ChangePinRequest;
use App\Http\Requests\Partner\PartnerLoginRequest;
use App\Models\AdminSecurityEvent;
use App\Models\PartnerUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Partner app authentication — phone + PIN.
 *
 * Hardening notes, since a 6-digit numeric secret on a public endpoint against
 * a shared-device threat model is the weakest surface in this feature:
 *
 *  - `throttle:partner-login` caps attempts per IP+phone AND per IP.
 *  - A per-account lockout sits behind that, because a distributed attacker
 *    defeats any IP-based limit.
 *  - Failures are written to `admin_security_events`, whose `type` is a plain
 *    string column and whose `admin_id` is nullable — so partner events need
 *    no migration. `security_events` was the wrong table: its `type` is a
 *    MySQL ENUM (widening it needs a MySQL-only migration, and getting that
 *    wrong has silently corrupted audit rows here before) and its `customer_id`
 *    is a foreign key to `customers`, which a partner is not.
 */
class PartnerAuthController extends Controller
{
    /**
     * POST /api/v1/partner/auth/login
     */
    public function login(PartnerLoginRequest $request): JsonResponse
    {
        $phone = PartnerUser::normalisePhone($request->input('phone'));

        /** @var PartnerUser|null $user */
        $user = PartnerUser::with('organisation')->where('phone', $phone)->first();

        // One message and one status for every failure mode below, so the
        // endpoint cannot be used to discover which phone numbers are
        // registered partners.
        $genericFailure = response()->json([
            'message' => 'Phone number or PIN is incorrect.',
            'code'    => 'invalid_credentials',
        ], 401);

        if (! $user) {
            $this->logSecurityEvent($request, 'partner_login_failed', 'Login attempt for unknown phone number', [
                'phone' => $phone,
            ]);

            return $genericFailure;
        }

        if ($user->isLocked()) {
            $this->logSecurityEvent($request, 'partner_login_locked_out', 'Login attempt on a locked partner account', [
                'phone'           => $phone,
                'partner_user_id' => $user->id,
                'locked_until'    => $user->locked_until?->toIso8601String(),
            ], 'warning');

            return response()->json([
                'message'      => 'Too many incorrect attempts. Try again shortly.',
                'code'         => 'account_locked',
                'locked_until' => $user->locked_until?->toIso8601String(),
            ], 423);
        }

        if (! Hash::check((string) $request->input('pin'), $user->pin_hash)) {
            $this->registerFailedAttempt($request, $user, $phone);

            return $genericFailure;
        }

        // Correct PIN, but the account or organisation is switched off. Checked
        // AFTER the PIN so a suspended partner's status is not disclosed to
        // someone who does not have their credentials.
        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account is no longer active. Please contact Okelcor.',
                'code'    => 'user_inactive',
            ], 403);
        }

        if (! $user->organisation?->isActive()) {
            return response()->json([
                'message' => 'This partner account has been suspended. Please contact Okelcor.',
                'code'    => 'org_suspended',
            ], 403);
        }

        $user->forceFill([
            'failed_pin_attempts' => 0,
            'locked_until'        => null,
            'last_login_at'       => now(),
        ])->save();

        // One token per login. Old tokens are left alone: a partner routinely
        // has the app open on more than one device, and revoking on login would
        // silently drop another device's unsynced offline queue.
        $token = $user->createToken('partner-app')->plainTextToken;

        $this->logSecurityEvent($request, 'partner_login_success', 'Partner signed in', [
            'partner_user_id' => $user->id,
            'partner_org_id'  => $user->partner_org_id,
        ]);

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->formatUser($user),
            ],
            'message' => 'Signed in.',
        ]);
    }

    /**
     * GET /api/v1/partner/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->formatUser($user->loadMissing('organisation')),
        ]);
    }

    /**
     * POST /api/v1/partner/auth/change-pin
     */
    public function changePin(ChangePinRequest $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_pin'), $user->pin_hash)) {
            $this->logSecurityEvent($request, 'partner_pin_change_failed', 'Incorrect current PIN on change attempt', [
                'partner_user_id' => $user->id,
            ], 'warning');

            return response()->json([
                'message' => 'Your current PIN is incorrect.',
                'errors'  => ['current_pin' => ['Your current PIN is incorrect.']],
            ], 422);
        }

        $newPin = (string) $request->input('new_pin');

        if (Hash::check($newPin, $user->pin_hash)) {
            return response()->json([
                'message' => 'Your new PIN must be different from your current one.',
                'errors'  => ['new_pin' => ['Your new PIN must be different from your current one.']],
            ], 422);
        }

        $user->forceFill([
            'pin_hash'        => Hash::make($newPin),
            'must_change_pin' => false,
            'pin_changed_at'  => now(),
        ])->save();

        $this->logSecurityEvent($request, 'partner_pin_changed', 'Partner changed their PIN', [
            'partner_user_id' => $user->id,
        ]);

        return response()->json([
            'data'    => $this->formatUser($user->loadMissing('organisation')),
            'message' => 'PIN updated.',
        ]);
    }

    /**
     * POST /api/v1/partner/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var PartnerUser $user */
        $user = $request->user();

        // Only this device's token — see the note in login().
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function registerFailedAttempt(Request $request, PartnerUser $user, string $phone): void
    {
        $max = (int) config('partner.pin.max_attempts', 5);

        $attempts = $user->failed_pin_attempts + 1;
        $locked   = $attempts >= $max;

        $user->forceFill([
            'failed_pin_attempts' => $locked ? 0 : $attempts,
            'locked_until'        => $locked
                ? now()->addMinutes((int) config('partner.pin.lockout_minutes', 15))
                : $user->locked_until,
        ])->save();

        $this->logSecurityEvent(
            $request,
            $locked ? 'partner_account_locked' : 'partner_login_failed',
            $locked
                ? 'Partner account locked after repeated incorrect PINs'
                : 'Incorrect PIN',
            [
                'phone'           => $phone,
                'partner_user_id' => $user->id,
                'attempt'         => $attempts,
            ],
            $locked ? 'critical' : 'warning',
        );
    }

    private function logSecurityEvent(
        Request $request,
        string $type,
        string $description,
        array $metadata = [],
        string $severity = 'info',
    ): void {
        AdminSecurityEvent::create([
            'type'        => $type,
            'severity'    => $severity,
            'admin_id'    => null, // nullable — this actor is a partner, not an admin
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'description' => $description,
            'metadata'    => $metadata,
        ]);
    }

    private function formatUser(PartnerUser $user): array
    {
        $org = $user->organisation;

        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'phone'           => $user->phone,
            'role'            => $user->role,
            'must_change_pin' => (bool) $user->must_change_pin,
            'last_login_at'   => $user->last_login_at?->toIso8601String(),
            'organisation'    => $org ? [
                'id'               => $org->id,
                'name'             => $org->name,
                'country'          => $org->country,
                'country_code'     => $org->country_code,
                'market'           => $org->market,
                'default_currency' => $org->default_currency,
            ] : null,
        ];
    }
}
