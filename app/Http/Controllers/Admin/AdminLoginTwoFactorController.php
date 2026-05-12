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

        $cacheKey = '2fa_challenge:' . $request->session_token;
        $adminId  = Cache::get($cacheKey);

        if (! $adminId) {
            RateLimiter::hit($key, 60);
            Log::warning('Admin 2FA login: invalid or expired session token', [
                'ip'           => $ip,
                'session_token' => substr($request->session_token, 0, 8) . '…',
            ]);
            return response()->json(['message' => 'The 2FA session has expired or is invalid. Please log in again.'], 401);
        }

        $admin = AdminUser::find($adminId);

        if (! $admin || ! $admin->is_active || ! $admin->hasTwoFactorEnabled()) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Authentication failed.'], 401);
        }

        $code   = $request->code;
        $passed = false;

        // Check TOTP code first
        if (ctype_digit($code) && strlen($code) === 6) {
            $secret = decrypt($admin->two_factor_secret);
            $passed = (bool) $this->google2fa->verifyKey($secret, $code);
        }

        // Fall back to recovery code check
        if (! $passed) {
            $passed = $this->consumeRecoveryCode($admin, $code);
        }

        if (! $passed) {
            RateLimiter::hit($key, 60);
            AdminAuditLogger::warning('2fa_failed', 'Admin 2FA login: invalid TOTP or recovery code', $request, $admin);
            AdminLoginHistory::record($admin, false, true, $request);
            return response()->json(['message' => 'The provided code is invalid.'], 422);
        }

        // Code accepted — clear challenge and issue token
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

        return response()->json(['data' => [
            'token' => $token,
            'user'  => $this->formatUser($admin->fresh()),
        ]]);
    }

    private function consumeRecoveryCode(AdminUser $admin, string $code): bool
    {
        if (! $admin->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(decrypt($admin->two_factor_recovery_codes), true);

        if (! is_array($codes)) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        $index      = array_search($normalized, $codes, true);

        if ($index === false) {
            return false;
        }

        // Remove the used recovery code
        array_splice($codes, $index, 1);
        $admin->update([
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
        ]);

        Log::warning('Admin 2FA: recovery code used', [
            'admin_id'          => $admin->id,
            'email'             => $admin->email,
            'remaining_codes'   => count($codes),
        ]);

        return true;
    }

    private function formatUser(AdminUser $u): array
    {
        return [
            'id'                  => $u->id,
            'name'                => $u->name,
            'first_name'          => $u->first_name,
            'last_name'           => $u->last_name,
            'display_name'        => $u->display_name,
            'email'               => $u->email,
            'role'                => $u->role,
            'role_label'          => AuthController::roleLabel($u->role),
            'last_login_at'         => $u->last_login_at?->toIso8601String(),
            'must_change_password'  => (bool) $u->must_change_password,
            'two_factor_enabled'    => $u->hasTwoFactorEnabled(),
            'two_factor_enabled_at' => $u->two_factor_confirmed_at?->toIso8601String(),
            'permissions'           => AdminPermissions::for($u->role),
        ];
    }
}
