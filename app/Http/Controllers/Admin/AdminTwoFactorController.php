<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    // -------------------------------------------------------------------------

    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(Str::random(5)) . '-' . strtoupper(Str::random(5)),
            range(1, 8)
        );
    }
}
