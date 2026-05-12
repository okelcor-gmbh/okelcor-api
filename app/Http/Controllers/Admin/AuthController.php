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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $ip  = $request->ip();
        $key = 'admin-login:' . $ip;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            Log::warning('Admin login blocked — rate limit exceeded', [
                'ip'    => $ip,
                'email' => $request->email,
            ]);
            return response()->json([
                'message' => "Too many failed login attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $admin = AdminUser::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            RateLimiter::hit($key, 60);
            AdminAuditLogger::warning('login_failed', 'Admin login failed — invalid credentials', $request, $admin ?? null, ['email_attempted' => $request->email]);
            if ($admin) {
                AdminLoginHistory::record($admin, false, false, $request);
            }
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors'  => ['email' => ['The provided credentials are incorrect.']],
            ], 422);
        }

        if (! $admin->is_active) {
            RateLimiter::hit($key, 60);
            AdminAuditLogger::warning('login_failed', 'Admin login attempt on inactive account', $request, $admin);
            AdminLoginHistory::record($admin, false, false, $request);
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        RateLimiter::clear($key);

        // 2FA challenge — do not issue a token yet
        if ($admin->hasTwoFactorEnabled()) {
            $sessionToken = (string) Str::uuid();
            Cache::put('2fa_challenge:' . $sessionToken, $admin->id, now()->addMinutes(5));

            AdminAuditLogger::info('2fa_challenge_issued', '2FA challenge issued — awaiting TOTP verification', $request, $admin);

            return response()->json([
                'data'         => ['session_token' => $sessionToken],
                'requires_2fa' => true,
                'message'      => 'Two-factor authentication required.',
            ]);
        }

        $admin->tokens()->delete();
        $token = $admin->createToken('admin-token')->plainTextToken;

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);

        AdminAuditLogger::info('login_success', 'Admin login successful (no 2FA)', $request, $admin);
        AdminLoginHistory::record($admin, true, false, $request);

        return response()->json(['data' => [
            'token' => $token,
            'user'  => $this->formatUser($admin->fresh()),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->formatUser($request->user())]);
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
            'role_label'          => self::roleLabel($u->role),
            'last_login_at'          => $u->last_login_at?->toIso8601String(),
            'must_change_password'   => (bool) $u->must_change_password,
            'two_factor_enabled'     => $u->hasTwoFactorEnabled(),
            'two_factor_enabled_at'  => $u->two_factor_confirmed_at?->toIso8601String(),
            'permissions'            => AdminPermissions::for($u->role),
        ];
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin'   => 'Super Admin',
            'admin'         => 'Admin',
            'editor'        => 'Editor',
            'order_manager' => 'Order Manager',
            default         => ucfirst($role),
        };
    }
}
