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
use PragmaRX\Google2FA\Google2FA;

class AdminLoginTwoFactorController extends Controller
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * POST /api/v1/admin/login/2fa
     *
     * Complete a login that required 2FA. Consumes the challenge UUID from
     * cache, verifies the TOTP code (or a recovery code), then issues the
     * Sanctum token and clears the challenge.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->handle($request);
        } catch (\Throwable $e) {
            Log::error('[2FA login] Unhandled exception', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'ip'    => $request->ip(),
            ]);
            return response()->json([
                'message' => 'A server error occurred during authentication. Please try again or contact support.',
            ], 500);
        }
    }

    private function handle(Request $request): JsonResponse
    {
        $request->validate([
            'session_token' => ['required', 'string', 'uuid'],
            'code'          => ['required', 'string'],
        ]);

        $ip  = $request->ip();
        $key = 'admin-2fa:' . $ip;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many failed attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        // ── Resolve challenge session ─────────────────────────────────────────
        $cacheKey = '2fa_challenge:' . $request->session_token;
        $adminId  = Cache::get($cacheKey);

        if (! $adminId) {
            RateLimiter::hit($key, 60);
            Log::warning('[2FA login] Invalid or expired session token', [
                'ip'            => $ip,
                'session_token' => substr($request->session_token, 0, 8) . '…',
            ]);
            return response()->json([
                'message' => 'The 2FA session has expired. Please log in again.',
            ], 401);
        }

        $admin = AdminUser::find($adminId);

        if (! $admin || ! $admin->is_active) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Authentication failed.'], 401);
        }

        if (! $admin->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);
            Log::warning('[2FA login] Admin has no confirmed 2FA but a challenge was issued', [
                'admin_id' => $admin->id,
                'ip'       => $ip,
            ]);
            return response()->json([
                'message' => '2FA is not configured for this account. Please contact an administrator.',
            ], 422);
        }

        // ── Verify TOTP or recovery code ─────────────────────────────────────
        $code   = $request->code;
        $passed = false;

        if (ctype_digit($code) && strlen($code) === 6) {
            $passed = $this->verifyTotp($admin, $code, $ip);
        }

        if (! $passed) {
            $passed = $this->consumeRecoveryCode($admin, $code);
        }

        if (! $passed) {
            RateLimiter::hit($key, 60);
            AdminAuditLogger::warning('2fa_failed', 'Admin 2FA login: invalid TOTP or recovery code', $request, $admin);
            AdminLoginHistory::record($admin, false, true, $request);
            return response()->json(['message' => 'The provided code is invalid.'], 422);
        }

        // ── Code accepted — issue token ───────────────────────────────────────
        Cache::forget($cacheKey);
        RateLimiter::clear($key);

        $admin->tokens()->delete();
        $token = $admin->createToken('admin-token')->plainTextToken;

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);

        AdminAuditLogger::info('login_success', 'Admin login successful via 2FA', $request, $admin);
        AdminLoginHistory::record($admin, true, true, $request);

        return response()->json([
            'data' => [
                'token' => $token,
                'user'  => $this->formatUser($admin->fresh()),
            ],
            'message' => 'Login successful.',
        ]);
    }

    // -------------------------------------------------------------------------

    private function verifyTotp(AdminUser $admin, string $code, string $ip): bool
    {
        if (! $admin->two_factor_secret) {
            Log::warning('[2FA login] Admin has no 2FA secret stored', [
                'admin_id' => $admin->id,
                'ip'       => $ip,
            ]);
            return false;
        }

        try {
            $secret = decrypt($admin->two_factor_secret);
        } catch (\Throwable $e) {
            Log::error('[2FA login] Failed to decrypt 2FA secret — APP_KEY mismatch?', [
                'admin_id' => $admin->id,
                'ip'       => $ip,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }

        try {
            // verifyKey allows ±1 window (30 s drift) by default
            return (bool) $this->google2fa->verifyKey($secret, $code);
        } catch (\Throwable $e) {
            Log::error('[2FA login] TOTP verification threw an exception', [
                'admin_id' => $admin->id,
                'ip'       => $ip,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        if (! $admin->two_factor_recovery_codes) {
            return false;
        }

        try {
            $codes = json_decode(decrypt($admin->two_factor_recovery_codes), true);
        } catch (\Throwable $e) {
            Log::error('[2FA login] Failed to decrypt recovery codes — APP_KEY mismatch?', [
                'admin_id' => $admin->id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }

        if (! is_array($codes)) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        $index      = array_search($normalized, $codes, true);

        if ($index === false) {
            return false;
        }

        array_splice($codes, $index, 1);
        $admin->update([
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
        ]);

        Log::warning('[2FA login] Recovery code consumed', [
            'admin_id'       => $admin->id,
            'email'          => $admin->email,
            'remaining_codes' => count($codes),
        ]);

        return true;
    }

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
            'permissions'           => AdminPermissions::for($u->role),
        ];
    }
}
