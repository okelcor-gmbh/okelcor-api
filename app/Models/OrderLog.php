<?php

namespace App\Models;

use App\Models\Concerns\RecordsStaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    use RecordsStaffActivity;

    /**
     * Every action string this codebase is allowed to write, and the single
     * source of truth for the `action` column's ENUM.
     *
     * The list existed only inside migrations before, restated in full on every
     * widening — a pattern that has now failed three times (`security_events.type`,
     * `order_logs.action` twice). Each failure looks the same: shipped code
     * writes a value the column rejects, the write sits behind a try/catch that
     * logs a warning and carries on, and the audit row is simply never created.
     * The payment milestone history does not exist on production for any order
     * because of it, and those rows cannot be reconstructed.
     *
     * Two things follow from keeping the list here. The migration builds its
     * ENUM from this constant instead of a copy, so the two cannot drift; and a
     * test asserts every literal written in `app/` appears below, so a fourth
     * instance is a red test rather than a silent hole in the trail.
     *
     * Append only. Removing a value truncates rows in an append-only trail.
     */
    public const ACTIONS = [
        'created',
        'status_changed',
        'cancelled',
        'deleted',
        'tracking_updated',
        'payment_status_changed',
        'document_generated',
        'document_uploaded',
        'document_deleted',
        'document_sent',
        'document_voided',
        'document_superseded',
        'document_generation_blocked_payment_stage',
        'order_confirmation_accepted',
        'order_confirmation_rejected',
        'order_confirmation_auto_generated',
        'customer_proposal_accepted',
        'customer_proposal_rejected',
        'acceptance_link_generated',
        'acceptance_request_sent',
        'proforma_generation_blocked_no_acceptance',
        'proforma_signed_returned',
        'premature_proforma_superseded',
        'financial_corrected',
        'financial_revision_requested',
        'financial_revision_approved',
        'financial_revision_rejected',
        'item_corrected',
        'item_added',
        'item_removed',
        'currency_converted',
        'totals_repaired',
        'totals_restored',
        'deposit_requested',
        'deposit_paid',
        'balance_due',
        'balance_paid',
        'shipment_released',
        'payment_milestone_email_sent',
        'payment_milestone_email_failed',
        'declaration_acknowledged',

        // Session 83 — dual sign-off and the document gates it relaxes.
        'signoff_given',
        'signoff_revoked',
        'signoff_bypassed',
        'document_gate_overridden',

        // Session 90 — the only backwards path through the payment ladder.
        // Every other milestone action moves an order forward; this one puts a
        // payment state back to what is true, and is the reason an order that
        // arrives at "paid" without anyone deciding it no longer needs a
        // developer to unstick.
        'payment_state_corrected',
    ];

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'order_ref',
        'admin_user_id',
        'admin_user_email',
        'action',
        'old_value',
        'new_value',
        'notes',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    /**
     * The single richest source in the ledger — this table already stamps who
     * did what to which order, and has been doing so since Session 5.
     */
    public function recordStaffActivity(StaffActivityRecorder $recorder): void
    {
        $recorder->fromOrderLog($this);
    }
}
