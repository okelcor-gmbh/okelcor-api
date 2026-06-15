<?php

namespace App\Services;

use App\Models\AdminNotification;
use Illuminate\Support\Facades\Log;

/**
 * CRM-3 — Writes generic per-admin-user notifications.
 *
 * `type` and `link` are deliberately generic so the same table/endpoints
 * can be reused for future event types without frontend changes.
 */
class AdminNotificationService
{
    public static function notify(int $adminUserId, string $type, string $title, ?string $message = null, ?string $link = null): void
    {
        try {
            AdminNotification::create([
                'admin_user_id' => $adminUserId,
                'type'          => $type,
                'title'         => $title,
                'message'       => $message,
                'link'          => $link,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AdminNotification write failed', [
                'admin_user_id' => $adminUserId,
                'type'          => $type,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
