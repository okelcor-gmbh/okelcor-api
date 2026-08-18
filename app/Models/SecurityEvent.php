<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    /**
     * Every event type this codebase is allowed to write, and the single source
     * of truth for the `type` column's ENUM.
     *
     * The list lived only inside migrations before, restated in full on every
     * widening — the pattern that has now failed four times in this project
     * (`security_events.type` in Session 73, `order_logs.action` in Sessions 75
     * and 76, and this column again here). `OrderLog::ACTIONS` was moved beside
     * its writers in Session 83 for exactly this reason and the equivalent for
     * this column was left open as a Known Gap. This closes it.
     *
     * The failure here is worse than the order-log one, and in the opposite
     * direction. `SecurityEventService::log()` does **not** swallow its
     * exceptions, so on a strict MySQL server a type missing from the ENUM does
     * not quietly lose an audit row — it throws, and takes down whatever the
     * customer was doing. Adding `email_verified` without this migration would
     * have made completing a password reset fail outright.
     *
     * Append only. Removing a value truncates rows in an audit trail.
     */
    public const TYPES = [
        // Auth / account security
        'failed_login',
        'suspicious_activity',
        'new_registration',
        'password_reset',
        'account_changes',
        'account_lockout',
        'account_unlock',
        'account_suspend',
        'account_ban',

        // Customer lifecycle (CRM-1 / CRM-8 / lead conversion / admin onboarding)
        'customer_pending_review_created',
        'customer_activated',
        'customer_created',
        'customer_invited',
        'customer_approved',
        'customer_rejected',
        'customer_blocked',
        'lead_converted_to_customer',

        // Session 91 — proving control of an email address, and an admin
        // vouching for one on the customer's behalf.
        'email_verified',
        'email_verification_sent',
        'email_verified_by_admin',
    ];

    protected $fillable = [
        'type', 'customer_id', 'ip_address', 'user_agent',
        'location', 'description', 'severity',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
