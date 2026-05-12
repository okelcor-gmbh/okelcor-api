<?php

namespace App\Services;

use App\Models\AdminSecurityEvent;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Centralized admin security event logger.
 *
 * Writes to admin_security_events table and mirrors to Laravel log.
 * Never logs raw tokens, passwords, or full user_agent strings beyond 500 chars.
 *
 * Standard event types:
 *   login_success, login_failed, 2fa_challenge_issued
 *   2fa_failed, 2fa_enabled, 2fa_disabled, recovery_code_used
 *   permission_denied, role_changed
 *   admin_created, admin_deleted
 *   order_deleted, document_download_denied, webhook_failed
 */
class AdminAuditLogger
{
    public static function info(
        string $type,
        string $description,
        Request $request,
        ?AdminUser $admin = null,
        array $metadata = []
    ): void {
        self::write('info', $type, $description, $request, $admin, $metadata);
    }

    public static function warning(
        string $type,
        string $description,
        Request $request,
        ?AdminUser $admin = null,
        array $metadata = []
    ): void {
        self::write('warning', $type, $description, $request, $admin, $metadata);
    }

    public static function critical(
        string $type,
        string $description,
        Request $request,
        ?AdminUser $admin = null,
        array $metadata = []
    ): void {
        self::write('critical', $type, $description, $request, $admin, $metadata);
    }

    private static function write(
        string $severity,
        string $type,
        string $description,
        Request $request,
        ?AdminUser $admin,
        array $metadata
    ): void {
        try {
            AdminSecurityEvent::create([
                'type'        => $type,
                'severity'    => $severity,
                'admin_id'    => $admin?->id,
                'admin_email' => $admin?->email,
                'admin_role'  => $admin?->role,
                'ip_address'  => $request->ip(),
                'user_agent'  => mb_substr((string) $request->userAgent(), 0, 500),
                'description' => $description,
                'metadata'    => empty($metadata) ? null : $metadata,
            ]);
        } catch (\Throwable $e) {
            // DB write failing must never take down a request
            Log::error('AdminAuditLogger DB write failed', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
        }

        $logContext = array_filter([
            'type'        => $type,
            'admin_id'    => $admin?->id,
            'admin_email' => $admin?->email,
            'ip'          => $request->ip(),
        ]);

        match ($severity) {
            'critical' => Log::critical("[admin-audit] {$description}", $logContext),
            'warning'  => Log::warning("[admin-audit] {$description}", $logContext),
            default    => Log::info("[admin-audit] {$description}", $logContext),
        };
    }
}
