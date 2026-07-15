<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Support\Facades\Log;

/**
 * Customer Portal Notifications — "Email = Inbox".
 *
 * The customer-facing twin of {@see AdminNotificationService}. Wherever a
 * transactional email is sent to a customer, call {@see self::notify()} so the
 * same subject/summary also lands in the in-app inbox (bell + /account
 * /notifications). Pass `email_sent: true` (or an explicit `email_sent_at`)
 * when the same event was emailed — that drives the "Emailed" tag in the UI.
 *
 * Design rules baked in:
 *  - title == email subject, body == email summary (the equivalence is the feature).
 *  - action_url must be a RELATIVE in-app path (validated; absolute URLs dropped).
 *  - Always create the in-app row (history); preferences only gate EMAIL.
 *  - Dedupe suppresses a second UNREAD row for the same logical event; a resend
 *    only refreshes email_sent_at on the existing row.
 *  - Every write is wrapped in try/catch — a notification failure must never
 *    break the business action that triggered it.
 */
class CustomerNotifier
{
    public const SEVERITIES = ['info', 'success', 'warning', 'urgent'];

    /**
     * type → preference group. Unknown types fall back to the 'account' group.
     * Mirrors the §4 enum / §3.6 preference flags from the frontend contract.
     */
    public const GROUPS = [
        'order_placed'         => 'orders',
        'order_confirmation'   => 'orders',
        'order_confirmed'      => 'orders',
        'payment_milestone'    => 'orders',
        'order_shipped'        => 'orders',
        'order_delivered'      => 'orders',
        'document_ready'       => 'documents',
        'quote_received'       => 'quotes',
        'quote_ready'          => 'quotes',
        'proposal_reminder'    => 'quotes',
        'account_approved'     => 'account',
        'access_request_update' => 'account',
        'verification_update'  => 'account',
        'security_alert'       => 'account',
        'welcome'              => 'account',
        'announcement'         => 'marketing',
    ];

    /** Operational/legal comms that always email regardless of preferences. */
    public const FORCED_EMAIL_GROUPS = ['orders'];
    public const FORCED_EMAIL_TYPES  = ['security_alert'];

    /**
     * Default preferences. email_marketing is opt-in (GDPR); everything else
     * defaults on. email_orders stays effectively forced via wantsEmail().
     * whatsapp_enabled defaults OFF, unlike email — WhatsApp Business Policy
     * requires opt-in consent for business-initiated messages; there is no
     * "everyone gets it unless they opt out" default here.
     */
    public static function defaultPreferences(): array
    {
        return [
            'inapp_enabled'    => true,
            'email_enabled'    => true,
            'email_orders'     => true,
            'email_documents'  => true,
            'email_quotes'     => true,
            'email_account'    => true,
            'email_marketing'  => false,
            'whatsapp_enabled' => false,
        ];
    }

    /** Stored preferences merged over defaults (so partial/legacy rows resolve). */
    public static function preferencesFor(Customer $customer): array
    {
        $stored = $customer->notification_preferences;
        $stored = is_array($stored) ? $stored : [];

        return array_merge(self::defaultPreferences(), array_intersect_key(
            $stored,
            self::defaultPreferences()
        ));
    }

    /** Whether the email for $type should be sent to this customer right now. */
    public static function wantsEmail(Customer $customer, string $type): bool
    {
        if (in_array($type, self::FORCED_EMAIL_TYPES, true)) {
            return true;
        }

        $group = self::GROUPS[$type] ?? 'account';
        if (in_array($group, self::FORCED_EMAIL_GROUPS, true)) {
            return true;
        }

        $prefs = self::preferencesFor($customer);
        if (! ($prefs['email_enabled'] ?? true)) {
            return false;
        }

        return (bool) ($prefs['email_' . $group] ?? true);
    }

    /**
     * Whether this customer has opted in to WhatsApp messages at all, and
     * has a phone number on file to send them to. No per-group granularity
     * yet (single on/off) — deliberate v1 scope, same as email's groups
     * would need if this ever needs "orders yes, marketing no" nuance.
     */
    public static function wantsWhatsApp(Customer $customer): bool
    {
        if (empty($customer->phone)) {
            return false;
        }

        return (bool) (self::preferencesFor($customer)['whatsapp_enabled'] ?? false);
    }

    /**
     * Create (or refresh) the in-app notification for a customer event.
     *
     * @param  array{
     *   severity?: string,
     *   action_url?: string|null,
     *   related_type?: string|null,
     *   related_id?: string|int|null,
     *   metadata?: array,
     *   dedupe_key?: string|null,
     *   email_sent?: bool,
     *   email_sent_at?: \DateTimeInterface|string|null,
     *   include_read?: bool
     * }  $opts
     * @return CustomerNotification|null  null on failure
     */
    public static function notify(
        Customer $customer,
        string $type,
        string $title,
        ?string $body = null,
        array $opts = []
    ): ?CustomerNotification {
        try {
            $severity = $opts['severity'] ?? self::defaultSeverity($type);
            $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'info';

            $actionUrl    = self::sanitizeActionUrl($opts['action_url'] ?? null);
            $relatedType  = $opts['related_type'] ?? null;
            $relatedId    = isset($opts['related_id']) ? (string) $opts['related_id'] : null;
            $metadata     = $opts['metadata'] ?? [];
            $includeRead  = (bool) ($opts['include_read'] ?? false);

            $emailSentAt = null;
            if (! empty($opts['email_sent_at'])) {
                $emailSentAt = $opts['email_sent_at'] instanceof \DateTimeInterface
                    ? $opts['email_sent_at']
                    : now();
            } elseif (! empty($opts['email_sent'])) {
                $emailSentAt = now();
            }

            $dedupeKey = $opts['dedupe_key']
                ?? self::buildDedupeKey($type, $relatedType, $relatedId, $metadata['stage'] ?? null);
            $metadata['dedupe_key'] = $dedupeKey;

            // Dedupe: refresh email_sent_at on an existing live row instead of
            // spawning a duplicate unread notification for the same event.
            $existing = self::findDuplicate($customer->id, $dedupeKey, $includeRead);
            if ($existing) {
                if ($emailSentAt && $existing->email_sent_at === null) {
                    $existing->update(['email_sent_at' => $emailSentAt]);
                }
                return $existing;
            }

            return CustomerNotification::create([
                'customer_id'   => $customer->id,
                'type'          => $type,
                'title'         => $title,
                'body'          => $body,
                'severity'      => $severity,
                'action_url'    => $actionUrl,
                'related_type'  => $relatedType,
                'related_id'    => $relatedId,
                'email_sent_at' => $emailSentAt,
                'metadata'      => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CustomerNotification write failed', [
                'customer_id' => $customer->id ?? null,
                'type'        => $type,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve a customer account by email and notify them. Returns null when no
     * active account owns that email (e.g. a guest order / non-account lead) —
     * those events simply have no in-app twin, which is expected.
     */
    public static function notifyByEmail(
        ?string $email,
        string $type,
        string $title,
        ?string $body = null,
        array $opts = []
    ): ?CustomerNotification {
        if (! $email) {
            return null;
        }

        try {
            $customer = Customer::where('email', $email)->first();
        } catch (\Throwable $e) {
            Log::warning('CustomerNotifier::notifyByEmail lookup failed', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $customer ? self::notify($customer, $type, $title, $body, $opts) : null;
    }

    /** Mark a single notification read (scoped to its owner). */
    public static function markRead(int $id, Customer $customer): ?CustomerNotification
    {
        $n = CustomerNotification::where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($n && $n->read_at === null) {
            $n->update(['read_at' => now()]);
        }

        return $n;
    }

    /** Mark all of a customer's unread notifications read. */
    public static function markAllRead(Customer $customer): int
    {
        return CustomerNotification::where('customer_id', $customer->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => now()]);
    }

    /** Dismiss (hide) a single notification (scoped to its owner). */
    public static function dismiss(int $id, Customer $customer): ?CustomerNotification
    {
        $n = CustomerNotification::where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($n && $n->dismissed_at === null) {
            $n->update([
                'dismissed_at' => now(),
                'read_at'      => $n->read_at ?? now(),
            ]);
        }

        return $n;
    }

    /** Count a customer's unread, non-dismissed notifications. */
    public static function unreadCount(Customer $customer): int
    {
        return CustomerNotification::forCustomer($customer->id)->unread()->count();
    }

    // -------------------------------------------------------------------------

    /** Suggested §6 severity for a type when the caller doesn't specify one. */
    private static function defaultSeverity(string $type): string
    {
        return match ($type) {
            'security_alert' => 'urgent',
            'order_confirmation', 'proposal_reminder' => 'warning',
            'order_placed', 'order_confirmed', 'order_delivered',
            'payment_milestone', 'quote_ready', 'account_approved' => 'success',
            default => 'info',
        };
    }

    private static function buildDedupeKey(string $type, ?string $relatedType, ?string $relatedId, ?string $stage): string
    {
        return implode(':', [$type, $relatedType ?? '-', $relatedId ?? '-', $stage ?? '-']);
    }

    private static function findDuplicate(int $customerId, string $dedupeKey, bool $includeRead): ?CustomerNotification
    {
        $query = CustomerNotification::where('customer_id', $customerId)
            ->where('metadata->dedupe_key', $dedupeKey)
            ->whereNull('dismissed_at');

        if (! $includeRead) {
            $query->whereNull('read_at');
        }

        return $query->latest('id')->first();
    }

    /**
     * action_url must be a relative in-app path (e.g. /account/orders/AB-1042).
     * Absolute/external URLs and protocol-relative URLs are rejected.
     */
    private static function sanitizeActionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Reject absolute (http://, https://, mailto:, etc.) and protocol-relative (//host).
        if (str_contains($url, '://') || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return null;
        }

        // Normalise to a leading slash.
        return str_starts_with($url, '/') ? $url : '/' . $url;
    }
}
