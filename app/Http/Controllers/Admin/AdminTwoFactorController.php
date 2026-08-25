<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLoginHistory;
use App\Models\AdminUser;
use App\Services\AdminAuditLogger;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class AdminTwoFactorController extends Controller
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * GET /api/v1/admin/2fa/status
     *
     * Return current 2FA state for the authenticated admin.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        $enabled        = $admin->hasTwoFactorEnabled();
        $confirmed      = $enabled;
        $recoveryCount  = 0;

        if ($enabled && $admin->two_factor_recovery_codes) {
            try {
                $codes         = json_decode(decrypt($admin->two_factor_recovery_codes), true);
                $recoveryCount = is_array($codes) ? count($codes) : 0;
            } catch (\Throwable) {
                $recoveryCount = 0;
            }
        }

        return response()->json([
            'data' => [
                'enabled'               => $enabled,
                'confirmed'             => $confirmed,
                'enabled_at'            => $admin->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_count'  => $recoveryCount,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/2fa/enable
     *
     * Generate a new TOTP secret and return the QR code SVG + secret.
     * The admin must then confirm with a valid code before 2FA is active.
     */
    public function enable(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        if ($admin->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->google2fa->generateSecretKey();

        $admin->update([
            'two_factor_secret'       => encrypt($secret),
            'two_factor_confirmed_at' => null,
        ]);

        $otpauthUri = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Okelcor'),
            $admin->email,
            $secret
        );

        Log::info('Admin 2FA enable initiated', [
            'admin_id' => $admin->id,
            'email'    => $admin->email,
        ]);

        return response()->json([
            'data'    => [
                'secret'      => $secret,
                'otpauth_uri' => $otpauthUri,
            ],
            'message' => 'Scan the QR code with your authenticator app, then confirm with a valid code.',
        ]);
    }

    /**
     * POST /api/v1/admin/2fa/confirm
     *
     * Confirm the TOTP code and activate 2FA. Also generates recovery codes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        if ($admin->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is already confirmed.'], 409);
        }

        if (! $admin->two_factor_secret) {
            return response()->json(['message' => 'No pending 2FA setup found. Call enable first.'], 422);
        }

        $secret = decrypt($admin->two_factor_secret);

        if (! $this->google2fa->verifyKey($secret, $request->code)) {
            Log::warning('Admin 2FA confirm: invalid code', [
                'admin_id' => $admin->id,
                'email'    => $admin->email,
                'ip'       => $request->ip(),
            ]);
            return response()->json(['message' => 'The provided code is invalid.'], 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $admin->update([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at'   => now(),
        ]);

        AdminAuditLogger::info('2fa_enabled', '2FA successfully enabled and confirmed', $request, $admin);

        return response()->json([
            'data'    => ['recovery_codes' => $recoveryCodes],
            'message' => 'Two-factor authentication has been enabled. Save your recovery codes now — they will not be shown again.',
        ]);
    }

    /**
     * POST /api/v1/admin/2fa/disable
     *
     * Disable 2FA. Requires current password for confirmation.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        if (! \Illuminate\Support\Facades\Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'The provided password is incorrect.'], 422);
        }

        if (! $admin->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is not currently enabled.'], 409);
        }

        $admin->update([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ]);

        AdminAuditLogger::warning('2fa_disabled', '2FA disabled by admin', $request, $admin);

        return response()->json(['message' => 'Two-factor authentication has been disabled.']);
    }

    /**
     * POST /api/v1/admin/2fa/recovery-codes/regenerate
     *
     * Regenerate recovery codes. Requires current password.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        if (! \Illuminate\Support\Facades\Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'The provided password is incorrect.'], 422);
        }

        if (! $admin->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor authentication is not enabled.'], 409);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $admin->update([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        Log::info('Admin 2FA recovery codes regenerated', [
            'admin_id' => $admin->id,
            'email'    => $admin->email,
            'ip'       => $request->ip(),
        ]);

        return response()->json([
            'data'    => ['recovery_codes' => $recoveryCodes],
            'message' => 'Recovery codes regenerated. Save them now — they will not be shown again.',
        ]);
    }

    /**
     * POST /api/v1/admin/2fa/setup/enable  (unauthenticated — temp_token only)
     *
     * First step of the mandatory setup flow for admins who do not yet have 2FA.
     * Generates a new TOTP secret and returns the OTPAuth URI for QR rendering.
     * Authenticated via a 10-minute temp_token issued by the login endpoint.
     */
    public function setupEnable(Request $request): JsonResponse
    {
        $request->validate(['temp_token' => ['required', 'string', 'uuid']]);

        $ip       = $request->ip();
        $cacheKey = '2fa_setup:' . $request->temp_token;
        $adminId  = Cache::get($cacheKey);

        if (! $adminId) {
            return response()->json([
                'message' => 'Setup session has expired. Please log in again.',
            ], 401);
        }

        $admin = AdminUser::find($adminId);

        if (! $admin || ! $admin->is_active) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Authentication failed.'], 401);
        }

        if ($admin->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->google2fa->generateSecretKey();

        $admin->update([
            'two_factor_secret'       => encrypt($secret),
            'two_factor_confirmed_at' => null,
        ]);

        $otpauthUri = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Okelcor'),
            $admin->email,
            $secret
        );

        Log::info('Admin mandatory 2FA setup initiated', [
            'admin_id' => $admin->id,
            'email'    => $admin->email,
            'ip'       => $ip,
        ]);

        return response()->json([
            'data'    => [
                'secret'      => $secret,
                'otpauth_uri' => $otpauthUri,
            ],
            'message' => 'Scan the QR code with your authenticator app, then confirm with a valid 6-digit code.',
        ]);
    }

    /**
     * POST /api/v1/admin/2fa/setup/confirm  (unauthenticated — temp_token only)
     *
     * Second step of the mandatory setup flow. Verifies the TOTP code, activates
     * 2FA on the account, generates recovery codes, and issues a full session token.
     * This is the only path that issues a token to an admin who had no 2FA.
     */
    public function setupConfirm(Request $request): JsonResponse
    {
        $request->validate([
            'temp_token' => ['required', 'string', 'uuid'],
            'code'       => ['required', 'string', 'digits:6'],
        ]);

        $ip       = $request->ip();
        $rateKey  = 'admin-2fa-setup:' . $ip;
        $cacheKey = '2fa_setup:' . $request->temp_token;

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            return response()->json([
                'message' => "Too many failed attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $adminId = Cache::get($cacheKey);

        if (! $adminId) {
            return response()->json([
                'message' => 'Setup session has expired. Please log in again.',
            ], 401);
        }

        $admin = AdminUser::find($adminId);

        if (! $admin || ! $admin->is_active || ! $admin->two_factor_secret) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Authentication failed.'], 401);
        }

        if ($admin->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        try {
            $secret = decrypt($admin->two_factor_secret);
        } catch (\Throwable $e) {
            Log::error('[2FA setup] Failed to decrypt secret', [
                'admin_id' => $admin->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Setup failed. Please log in and try again.'], 500);
        }

        if (! $this->google2fa->verifyKey($secret, $request->code)) {
            RateLimiter::hit($rateKey, 60);
            Log::warning('[2FA setup] Invalid TOTP code during mandatory setup', [
                'admin_id' => $admin->id,
                'ip'       => $ip,
            ]);
            return response()->json(['message' => 'The provided code is invalid.'], 422);
        }

        // ── Activate 2FA ────────────────────────────────────────────────────
        $recoveryCodes = $this->generateRecoveryCodes();

        $admin->update([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at'   => now(),
        ]);

        // ── Clean up setup session ────────────────────────────────────────
        Cache::forget($cacheKey);
        RateLimiter::clear($rateKey);

        // ── Issue full session token ──────────────────────────────────────
        $admin->tokens()->delete();
        $ttl       = (int) config('auth.admin_session_ttl_minutes', 300);
        $expiresAt = now()->addMinutes($ttl);
        $token     = $admin->createToken('admin-token', ['*'], $expiresAt)->plainTextToken;

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);

        AdminAuditLogger::info('admin_2fa_enabled', '2FA mandatory setup completed — full session issued', $request, $admin);
        AdminLoginHistory::record($admin, true, true, $request);

        return response()->json([
            'data' => [
                'token'          => $token,
                'expires_at'     => $expiresAt->toIso8601String(),
                'user'           => $this->formatUser($admin->fresh()),
                'recovery_codes' => $recoveryCodes,
            ],
            'message' => 'Two-factor authentication enabled. Save your recovery codes — they will not be shown again. Login successful.',
        ], 201);
    }

    // -------------------------------------------------------------------------

    private function formatUser(AdminUser $u): array
    {
        return [
            'id'                    => $u->id,
            'name'                  => $u->name,
            'first_name'            => $u->first_name,
            'last_name'             => $u->last_name,
            'display_name'          => $u->display_name,
            'email'                 => $u->email,
            'role'                  => $u->role,
            'role_label'            => AuthController::roleLabel($u->role),
            'last_login_at'         => $u->last_login_at?->toIso8601String(),
            'must_change_password'  => (bool) $u->must_change_password,
            'two_factor_enabled'    => $u->hasTwoFactorEnabled(),
            'two_factor_enabled_at' => $u->two_factor_confirmed_at?->toIso8601String(),
            'permissions'           => $u->effectivePermissions(),
        ];
    }

    // -------------------------------------------------------------------------

    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5)),
            range(1, 8)
        );
    }
}
