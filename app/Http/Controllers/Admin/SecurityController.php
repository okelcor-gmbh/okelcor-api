<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminTwoFactorNotice;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\LoginHistory;
use App\Models\SecurityEvent;
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

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;

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

        Log::info('Admin 2FA notice batch completed', [
            'performed_by' => $sender->id,
            'action'       => 'two_factor_notice_sent',
            'sent'         => $sent,
            'skipped'      => $skipped,
            'failed'       => $failed,
        ]);

        return response()->json([
            'data'    => compact('sent', 'skipped', 'failed'),
            'message' => '2FA notice emails sent.',
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

        $lockedToday = SecurityEvent::where('type', 'account_lockout')
            ->where('created_at', '>=', $today)
            ->count();

        $failedAttemptsToday = LoginHistory::where('success', false)
            ->where('created_at', '>=', $today)
            ->count();

        $newRegistrationsToday = Customer::where('created_at', '>=', $today)->count();

        $suspiciousAccounts = Customer::where('status', 'suspended')->count();

        $suspendedToday = SecurityEvent::where('type', 'account_suspend')
            ->where('created_at', '>=', $today)
            ->count();

        $bannedToday = SecurityEvent::where('type', 'account_ban')
            ->where('created_at', '>=', $today)
            ->count();

        return response()->json([
            'data' => [
                'locked_today'           => $lockedToday,
                'failed_attempts_today'  => $failedAttemptsToday,
                'new_registrations_today' => $newRegistrationsToday,
                'suspicious_accounts'    => $suspiciousAccounts,
                'suspended_today'        => $suspendedToday,
                'banned_today'           => $bannedToday,
            ],
        ]);
    }

    // ── GET /admin/security/events ────────────────────────────────────────────

    public function events(Request $request): JsonResponse
    {
        $request->validate([
            'type'        => ['nullable', 'in:failed_login,suspicious_activity,new_registration,password_reset,account_changes,account_lockout,account_unlock,account_suspend,account_ban'],
            'customer_id' => ['nullable', 'integer'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = SecurityEvent::with('customer:id,email')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $paginated->map(fn ($e) => [
                'id'             => $e->id,
                'type'           => $e->type,
                'severity'       => $e->severity,
                'description'    => $e->description,
                'customer_id'    => $e->customer_id,
                'customer_email' => $e->customer?->email,
                'ip_address'     => $e->ip_address,
                'user_agent'     => $e->user_agent,
                'location'       => $e->location,
                'created_at'     => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }
}
