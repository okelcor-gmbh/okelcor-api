<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminTwoFactorNotice;
use App\Models\AdminLoginHistory;
use App\Models\AdminSecurityEvent;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SecurityController extends Controller
{
    // ── POST /admin/security/send-2fa-notices ────────────────────────────────

    public function sendTwoFactorNotices(Request $request): JsonResponse
    {
        $graceUntil = config('auth.admin_2fa_grace_until');
        $sender     = $request->user();

        // Target: active, non-super_admin users without confirmed 2FA
        $users = AdminUser::where('is_active', true)
            ->where('role', '!=', 'super_admin')
            ->whereNull('two_factor_confirmed_at')
            ->orderBy('email')
            ->get();

        $eligible = $users->count();
        $sent     = 0;
        $skipped  = 0;
        $failed   = 0;

        if ($eligible === 0) {
            Log::info('Admin 2FA notice batch: no eligible recipients', [
                'performed_by' => $sender->id,
                'action'       => 'two_factor_notice_sent',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'No admins currently require a 2FA reminder.',
                'data'    => ['eligible' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0],
            ]);
        }

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new AdminTwoFactorNotice($user, $graceUntil));

                Log::info('2FA notice sent', [
                    'recipient_id'    => $user->id,
                    'recipient_email' => $user->email,
                    'sent_by'         => $sender->id,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                Log::error('2FA notice failed', [
                    'recipient_id'    => $user->id,
                    'recipient_email' => $user->email,
                    'sent_by'         => $sender->id,
                    'error'           => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        $success = $failed === 0;
        $message = $sent > 0
            ? "{$sent} admin " . ($sent === 1 ? 'user' : 'users') . " received the 2FA notice."
            : 'No emails were sent.';

        Log::info('Admin 2FA notice batch completed', [
            'performed_by' => $sender->id,
            'action'       => 'two_factor_notice_sent',
            'eligible'     => $eligible,
            'sent'         => $sent,
            'skipped'      => $skipped,
            'failed'       => $failed,
        ]);

        return response()->json([
            'success' => $success,
            'message' => $message,
            'data'    => compact('eligible', 'sent', 'skipped', 'failed'),
        ]);
    }

    // ── GET /admin/security/2fa-status ───────────────────────────────────────

    public function twoFactorStatus(): JsonResponse
    {
        $users = AdminUser::orderBy('name')->get();

        return response()->json([
            'data' => $users->map(fn (AdminUser $u) => [
                'id'                    => $u->id,
                'name'                  => $u->name,
                'email'                 => $u->email,
                'role'                  => $u->role,
                'is_active'             => (bool) $u->is_active,
                'two_factor_enabled'    => $u->hasTwoFactorEnabled(),
                'two_factor_enabled_at' => $u->two_factor_confirmed_at?->toIso8601String(),
                'last_login_at'         => $u->last_login_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    // ── GET /admin/security/summary ───────────────────────────────────────────

    public function summary(): JsonResponse
    {
        $today = now()->startOfDay();

        // Admin 2FA adoption
        $totalAdmins      = AdminUser::where('is_active', true)->count();
        $twoFaEnabled     = AdminUser::where('is_active', true)->whereNotNull('two_factor_confirmed_at')->count();
        $twoFaAdoptionPct = $totalAdmins > 0 ? round(($twoFaEnabled / $totalAdmins) * 100, 1) : 0;

        // Active admin sessions (Sanctum tokens for AdminUser)
        $activeSessions = DB::table('personal_access_tokens')
            ->where('tokenable_type', AdminUser::class)
            ->count();

        // Admin login stats today
        $failedLoginsToday    = AdminLoginHistory::where('success', false)
            ->where('created_at', '>=', $today)
            ->count();
        $successfulLoginsToday = AdminLoginHistory::where('success', true)
            ->where('created_at', '>=', $today)
            ->count();

        // Admin security events today
        $permissionDeniedToday = AdminSecurityEvent::where('type', 'permission_denied')
            ->where('created_at', '>=', $today)
            ->count();

        $webhookFailuresToday = AdminSecurityEvent::where('type', 'webhook_failed')
            ->where('created_at', '>=', $today)
            ->count();

        $criticalEventsToday = AdminSecurityEvent::where('severity', 'critical')
            ->where('created_at', '>=', $today)
            ->count();

        // Recent events
        $recentEvents = AdminSecurityEvent::orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => $this->formatEvent($e));

        return response()->json([
            'data' => [
                'admins' => [
                    'total'              => $totalAdmins,
                    'two_fa_enabled'     => $twoFaEnabled,
                    'two_fa_adoption_pct' => $twoFaAdoptionPct,
                    'active_sessions'    => $activeSessions,
                ],
                'today' => [
                    'failed_logins'        => $failedLoginsToday,
                    'successful_logins'    => $successfulLoginsToday,
                    'permission_denied'    => $permissionDeniedToday,
                    'webhook_failures'     => $webhookFailuresToday,
                    'critical_events'      => $criticalEventsToday,
                ],
                'recent_events' => $recentEvents,
            ],
        ]);
    }

    // ── GET /admin/security/events ────────────────────────────────────────────

    public function events(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => ['nullable', 'string', 'max:60'],
            'admin_id'  => ['nullable', 'integer'],
            'severity'  => ['nullable', 'in:info,warning,critical'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AdminSecurityEvent::orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('admin_id')) {
            $query->where('admin_id', $request->integer('admin_id'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->map(fn ($e) => $this->formatEvent($e))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // ── GET /admin/security/login-history ────────────────────────────────────

    public function loginHistory(Request $request): JsonResponse
    {
        $request->validate([
            'admin_id'  => ['nullable', 'integer'],
            'success'   => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AdminLoginHistory::orderByDesc('created_at');

        if ($request->filled('admin_id')) {
            $query->where('admin_id', $request->integer('admin_id'));
        }
        if ($request->filled('success')) {
            $query->where('success', $request->boolean('success'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->map(fn ($h) => [
                'id'          => $h->id,
                'admin_id'    => $h->admin_id,
                'admin_email' => $h->admin_email,
                'success'     => $h->success,
                'two_fa_used' => $h->two_fa_used,
                'ip_address'  => $h->ip_address,
                'user_agent'  => $h->user_agent,
                'created_at'  => $h->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    // ── Formatter ─────────────────────────────────────────────────────────────

    private function formatEvent(AdminSecurityEvent $e): array
    {
        return [
            'id'          => $e->id,
            'type'        => $e->type,
            'severity'    => $e->severity,
            'admin_id'    => $e->admin_id,
            'admin_email' => $e->admin_email,
            'admin_role'  => $e->admin_role,
            'ip_address'  => $e->ip_address,
            'description' => $e->description,
            'metadata'    => $e->metadata,
            'created_at'  => $e->created_at?->toIso8601String(),
        ];
    }
}
