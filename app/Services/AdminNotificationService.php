<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Support\AdminPermissions;
use App\Support\AdminPushCategories;
use Illuminate\Support\Facades\Log;

/**
 * CRM-3B — Admin notification center & assignment work queue.
 *
 * Writes per-admin-user notifications and resolves role/permission fan-out.
 * All writes are wrapped in try/catch so a notification failure can never
 * break the business action that triggered it.
 *
 * Dedupe: a stable `dedupe_key` is stored in metadata. By default a new
 * notification is suppressed when an existing UNREAD (and not dismissed) one
 * already carries the same key for the same user. Scheduled/recurring events
 * (e.g. follow-up due) pass $includeRead = true so we also suppress against
 * already-read rows, preventing daily re-spam for the same due date.
 */
class AdminNotificationService
{
    public const SEVERITIES = ['info', 'success', 'warning', 'urgent'];

    /**
     * Create a notification for a single admin user.
     *
     * @return AdminNotification|null  null when skipped (dedupe) or on failure
     */
    public static function notifyUser(
        int $adminUserId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        string $severity = 'info',
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $metadata = [],
        ?string $dedupeKey = null,
        bool $includeRead = false
    ): ?AdminNotification {
        try {
            $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'info';
            $dedupeKey ??= self::buildDedupeKey($type, $relatedType, $relatedId);

            if (self::isDuplicate($adminUserId, $dedupeKey, $includeRead)) {
                return null;
            }

            $metadata['dedupe_key'] = $dedupeKey;

            $notification = AdminNotification::create([
                'admin_user_id' => $adminUserId,
                'type'          => $type,
                'severity'      => $severity,
                'title'         => $title,
                'body'          => $body,
                'message'       => $body,        // legacy mirror
                'action_url'    => $actionUrl,
                'link'          => $actionUrl,   // legacy mirror
                'related_type'  => $relatedType,
                'related_id'    => $relatedId,
                'metadata'      => $metadata,
            ]);

            // Mobile push (companion app) — same event, one more channel.
            // No-op for an admin with no registered device; any Expo
            // failure is caught and logged inside the service itself.
            // category tags which action buttons (if any) the push renders
            // (see AdminPushCategories); related_type/related_id let a
            // tapped action call the right endpoint without opening the app.
            app(ExpoPushService::class)->sendToAdmin(
                $adminUserId, $title, $body, $actionUrl,
                category: AdminPushCategories::forType($type),
                data: array_filter(['related_type' => $relatedType, 'related_id' => $relatedId, 'type' => $type]),
            );

            return $notification;
        } catch (\Throwable $e) {
            Log::warning('AdminNotification write failed', [
                'admin_user_id' => $adminUserId,
                'type'          => $type,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fan a notification out to every active admin who holds a given permission.
     *
     * @param  string  $permission  e.g. 'customers.manage' (key from AdminPermissions::MAP)
     * @return int  number of notifications actually created
     */
    public static function notifyPermission(
        string $permission,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        string $severity = 'info',
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $metadata = [],
        ?string $dedupeKey = null,
        bool $includeRead = false
    ): int {
        $roles = AdminPermissions::MAP[$permission] ?? [];
        if ($roles === []) {
            return 0;
        }

        return self::notifyRoles(
            $roles, $type, $title, $body, $actionUrl, $severity,
            $relatedType, $relatedId, $metadata, $dedupeKey, $includeRead
        );
    }

    /**
     * Fan a notification out to every active admin in the given role(s).
     *
     * @param  string|string[]  $roles
     * @return int  number of notifications actually created
     */
    public static function notifyRoles(
        string|array $roles,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        string $severity = 'info',
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $metadata = [],
        ?string $dedupeKey = null,
        bool $includeRead = false
    ): int {
        $roles = (array) $roles;
        $created = 0;

        try {
            $userIds = AdminUser::query()
                ->whereIn('role', $roles)
                ->where('is_active', true)
                ->pluck('id');
        } catch (\Throwable $e) {
            Log::warning('AdminNotification role fan-out failed', [
                'roles' => $roles,
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        foreach ($userIds as $id) {
            $n = self::notifyUser(
                (int) $id, $type, $title, $body, $actionUrl, $severity,
                $relatedType, $relatedId, $metadata, $dedupeKey, $includeRead
            );
            if ($n) {
                $created++;
            }
        }

        return $created;
    }

    /** Mark a single notification read (scoped to its owner). */
    public static function markRead(int $id, AdminUser $user): ?AdminNotification
    {
        $n = AdminNotification::where('id', $id)
            ->where('admin_user_id', $user->id)
            ->first();

        if ($n && $n->read_at === null) {
            $n->update(['read_at' => now()]);
        }

        return $n;
    }

    /** Mark all of a user's unread notifications read. */
    public static function markAllRead(AdminUser $user): int
    {
        return AdminNotification::where('admin_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Dismiss (hide) a single notification (scoped to its owner). */
    public static function dismiss(int $id, AdminUser $user): ?AdminNotification
    {
        $n = AdminNotification::where('id', $id)
            ->where('admin_user_id', $user->id)
            ->first();

        if ($n && $n->dismissed_at === null) {
            $n->update([
                'dismissed_at' => now(),
                'read_at'      => $n->read_at ?? now(),
            ]);
        }

        return $n;
    }

    /** Count a user's unread, non-dismissed notifications. */
    public static function unreadCount(AdminUser $user): int
    {
        return AdminNotification::forUser($user->id)->unread()->count();
    }

    // -------------------------------------------------------------------------

    /**
     * Legacy CRM-3 entry point. Kept so existing callers keep working.
     */
    public static function notify(int $adminUserId, string $type, string $title, ?string $message = null, ?string $link = null): void
    {
        self::notifyUser($adminUserId, $type, $title, $message, $link);
    }

    private static function buildDedupeKey(string $type, ?string $relatedType, ?int $relatedId): string
    {
        return implode(':', [$type, $relatedType ?? '-', $relatedId ?? '-']);
    }

    private static function isDuplicate(int $adminUserId, string $dedupeKey, bool $includeRead): bool
    {
        $query = AdminNotification::where('admin_user_id', $adminUserId)
            ->where('metadata->dedupe_key', $dedupeKey)
            ->whereNull('dismissed_at');

        if (! $includeRead) {
            $query->whereNull('read_at');
        }

        return $query->exists();
    }
}
