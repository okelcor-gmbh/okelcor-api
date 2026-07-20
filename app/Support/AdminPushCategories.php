<?php

namespace App\Support;

/**
 * Maps an admin-notification `type` to a mobile push notification category
 * identifier. The category ITSELF (which action buttons it shows — e.g.
 * Approve/Reject, Reply/View) is registered client-side in the Expo/React
 * Native app; this map only decides which category identifier a given
 * notification type gets, so e.g. a `financial_revision_requested` push
 * renders with Approve/Reject buttons right on the lock screen, no app-open
 * required. See FRONTEND_NOTE_admin-mobile-app-v2-premium.md, Pillar 3.
 *
 * Keep this additive — an unmapped type just falls back to a plain
 * notification (no action buttons), never breaks anything.
 */
class AdminPushCategories
{
    public const MAP = [
        'financial_revision_requested' => 'financial_revision_request',
        'financial_revision_approved'  => 'financial_revision_result',
        'financial_revision_rejected'  => 'financial_revision_result',
        'email_reply_received'         => 'inbox_reply',
        'customer_message_reply'       => 'inbox_reply',
        'inbound_email_lead_received'  => 'new_lead',
        'follow_up_due'                => 'follow_up_due',
        'crisp_message_received'       => 'crisp_reply',
    ];

    public static function forType(string $type): ?string
    {
        return self::MAP[$type] ?? null;
    }
}
