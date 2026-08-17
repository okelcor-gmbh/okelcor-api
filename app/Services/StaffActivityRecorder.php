<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\CustomerCommunication;
use App\Models\FinanceInvoice;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderSignoff;
use App\Models\PartnerSaleAudit;
use App\Models\StaffActivity;
use App\Models\TradeDocument;
use Illuminate\Support\Facades\Log;

/**
 * Turns work the system already records into a per-person contribution ledger.
 *
 * Not a new data source. Every row here is derived from a trail this API has
 * been writing for months — order logs, trade documents, sign-offs, customer
 * replies, campaigns, invoice register entries, partner verifications. The
 * ledger is a reading of that history, which is why it can be backfilled rather
 * than only accumulating from the day it ships.
 *
 * Four rules hold this together, and each one is load-bearing:
 *
 * 1. **Driven by model events, not call sites.** There are dozens of places an
 *    order log is written and five that create a trade document. A guarantee
 *    that depends on each future one remembering to call a recorder is not a
 *    guarantee — the same reasoning InvoiceRegistrar and MarketingContact's
 *    market sync both follow.
 *
 * 2. **No person, no row.** A great many order logs are written by the customer
 *    accepting something, by a webhook, or by a scheduled command. Attributing
 *    those to whoever happened to be authenticated at the time would be worse
 *    than not recording them, so they are skipped outright.
 *
 * 3. **Never fails the thing that triggered it.** An order that confirmed
 *    correctly must not fail because a reporting row could not be written. The
 *    ledger is downstream of the work, never in front of it.
 *
 * 4. **Idempotent.** Keyed on (source_type, source_id, action), which is the
 *    table's own unique constraint. Re-saving a model updates its row rather
 *    than adding a second, and the backfill can be run as many times as needed
 *    without doubling anybody's month.
 */
class StaffActivityRecorder
{
    /**
     * Which area of the business an order-log action belongs to.
     *
     * Anything not named here falls to `orders`, which is the right default:
     * the enum is the order lifecycle, and a new action added by a future
     * session lands in the category a reader would expect rather than in a
     * bucket called "other".
     */
    private const ORDER_LOG_CATEGORIES = [
        // Trade documents — raising, sending, withdrawing.
        'document_generated'                        => 'documents',
        'document_uploaded'                         => 'documents',
        'document_deleted'                          => 'documents',
        'document_sent'                             => 'documents',
        'document_voided'                           => 'documents',
        'document_superseded'                       => 'documents',
        'document_generation_blocked_payment_stage' => 'documents',
        'document_gate_overridden'                  => 'documents',
        'acceptance_link_generated'                 => 'documents',
        'acceptance_request_sent'                   => 'documents',
        'order_confirmation_auto_generated'         => 'documents',
        'proforma_generation_blocked_no_acceptance' => 'documents',
        'proforma_signed_returned'                  => 'documents',
        'premature_proforma_superseded'             => 'documents',

        // Money — corrections, milestones, signatures, declarations.
        'payment_status_changed'         => 'finance',
        'financial_corrected'            => 'finance',
        'financial_revision_requested'   => 'finance',
        'financial_revision_approved'    => 'finance',
        'financial_revision_rejected'    => 'finance',
        'totals_repaired'                => 'finance',
        'totals_restored'                => 'finance',
        'currency_converted'             => 'finance',
        'deposit_requested'              => 'finance',
        'deposit_paid'                   => 'finance',
        'balance_due'                    => 'finance',
        'balance_paid'                   => 'finance',
        'payment_milestone_email_sent'   => 'finance',
        'payment_milestone_email_failed' => 'finance',
        'declaration_acknowledged'       => 'finance',
        'signoff_given'                  => 'finance',
        'signoff_revoked'                => 'finance',
        'signoff_bypassed'               => 'finance',
    ];

    /**
     * Order-log actions a customer performs, not a member of staff.
     *
     * These arrive with a null admin and would be skipped anyway, but naming
     * them makes the intent explicit rather than incidental — a future change
     * that starts stamping an admin on them should not silently credit that
     * person with the customer's decision.
     */
    private const CUSTOMER_ACTIONS = [
        'order_confirmation_accepted',
        'order_confirmation_rejected',
        'customer_proposal_accepted',
        'customer_proposal_rejected',
    ];

    // -------------------------------------------------------------------------
    // Sources
    // -------------------------------------------------------------------------

    public function fromOrderLog(OrderLog $log): ?StaffActivity
    {
        if (in_array($log->action, self::CUSTOMER_ACTIONS, true)) {
            return null;
        }

        return $this->write([
            'category'      => self::ORDER_LOG_CATEGORIES[$log->action] ?? 'orders',
            'action'        => $log->action,
            'subject_type'  => 'order',
            'subject_id'    => $log->order_id,
            'subject_label' => $log->order_ref,
            'source_type'   => 'order_log',
            'source_id'     => $log->id,
            'occurred_at'   => $log->created_at ?? now(),
            'metadata'      => array_filter([
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
            ], fn ($v) => $v !== null && $v !== ''),
        ], $log->admin_user_id);
    }

    public function fromTradeDocument(TradeDocument $document): ?StaffActivity
    {
        // Superseded and voided documents keep their ledger row, unlike the
        // invoice register. Withdrawing a document does not un-do the work of
        // having raised it, and a month that silently loses its entries when a
        // customer asks for a correction reads as though nothing was done.
        return $this->write([
            'category'      => 'documents',
            'action'        => 'document_issued',
            'subject_type'  => 'trade_document',
            'subject_id'    => $document->id,
            'subject_label' => $document->number ?: $document->order_ref,
            'source_type'   => 'trade_document',
            'source_id'     => $document->id,
            'occurred_at'   => $document->issued_at ?? $document->created_at ?? now(),
            'metadata'      => array_filter([
                'type'      => $document->type,
                'order_ref' => $document->order_ref,
                'status'    => $document->status,
            ]),
        ], $document->issued_by);
    }

    public function fromSignoff(OrderSignoff $signoff): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'order_signed_off',
            'subject_type'  => 'order',
            'subject_id'    => $signoff->order_id,
            'subject_label' => $signoff->order_ref,
            'source_type'   => 'order_signoff',
            'source_id'     => $signoff->id,
            'occurred_at'   => $signoff->signed_at ?? $signoff->created_at ?? now(),
            'metadata'      => array_filter(['slot' => $signoff->slot]),
        ], $signoff->admin_user_id, $signoff->admin_name, $signoff->admin_role);
    }

    /**
     * A reply to a customer. Inbound messages are not staff work — recording
     * them would credit whoever the message was addressed to for the customer
     * having written in.
     */
    public function fromCommunication(CustomerCommunication $communication): ?StaffActivity
    {
        if ($communication->direction !== 'outbound') {
            return null;
        }

        return $this->write([
            'category'      => 'support',
            'action'        => 'customer_replied',
            'subject_type'  => 'customer',
            'subject_id'    => $communication->customer_id,
            'subject_label' => $communication->subject ?: 'Message to customer',
            'source_type'   => 'customer_communication',
            'source_id'     => $communication->id,
            'occurred_at'   => $communication->completed_at ?? $communication->created_at ?? now(),
            'metadata'      => array_filter([
                'channel' => $communication->channel,
                'type'    => $communication->type,
            ]),
        ], $communication->admin_user_id);
    }

    public function fromCampaign(BulkEmailCampaign $campaign): ?StaffActivity
    {
        return $this->write([
            'category'      => 'marketing',
            'action'        => 'campaign_built',
            'subject_type'  => 'campaign',
            'subject_id'    => $campaign->id,
            'subject_label' => $campaign->subject ?: ('Campaign #' . $campaign->id),
            'source_type'   => 'bulk_email_campaign',
            'source_id'     => $campaign->id,
            'occurred_at'   => $campaign->created_at ?? now(),
            'metadata'      => array_filter(['status' => $campaign->status]),
        ], $campaign->created_by);
    }

    /**
     * A finance invoice entered by hand. Rows the invoice registrar writes for
     * this system's own invoices are skipped — nobody typed those, and crediting
     * finance with them would count the same work twice, once here and once
     * through the order log that raised the invoice.
     */
    public function fromFinanceInvoice(FinanceInvoice $invoice): ?StaffActivity
    {
        if ($invoice->isAutoRegistered()) {
            return null;
        }

        return $this->write([
            'category'      => 'finance',
            'action'        => 'finance_invoice_recorded',
            'subject_type'  => 'finance_invoice',
            'subject_id'    => $invoice->id,
            'subject_label' => $invoice->external_number,
            'source_type'   => 'finance_invoice',
            'source_id'     => $invoice->id,
            'occurred_at'   => $invoice->created_at ?? now(),
            'metadata'      => array_filter([
                'system'    => $invoice->system,
                'order_ref' => $invoice->order_ref,
            ]),
        ], $invoice->recorded_by);
    }

    /**
     * A partner sale verified or corrected by a member of staff.
     *
     * The audit trail records both sides of this relationship — the partner
     * entering their own sale is `partner_user`, and that is their work, not
     * ours. Only `admin_user` rows belong in a staff ledger.
     */
    public function fromPartnerSaleAudit(PartnerSaleAudit $audit): ?StaffActivity
    {
        if ($audit->actor_type !== 'admin_user' || $audit->actor_id === null) {
            return null;
        }

        return $this->write([
            'category'      => 'partners',
            'action'        => 'partner_sale_' . $audit->action,
            'subject_type'  => 'partner_sale',
            'subject_id'    => $audit->partner_sale_id,
            'subject_label' => 'Partner sale #' . $audit->partner_sale_id,
            'source_type'   => 'partner_sale_audit',
            'source_id'     => $audit->id,
            'occurred_at'   => $audit->created_at ?? now(),
            'metadata'      => [],
        ], $audit->actor_id, $audit->actor_label);
    }

    // -------------------------------------------------------------------------

    /**
     * Writes one ledger row, resolving who it belongs to.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(
        array $attributes,
        ?int $adminUserId,
        ?string $nameSnapshot = null,
        ?string $roleSnapshot = null,
    ): ?StaffActivity {
        if (! StaffActivity::ledgerAvailable()) {
            return null;
        }

        $adminUserId ??= $this->currentAdminId();

        // Rule 2. Work with nobody behind it is not a contribution.
        if ($adminUserId === null) {
            return null;
        }

        if ($nameSnapshot === null || $roleSnapshot === null) {
            $admin = $this->admin($adminUserId);

            if ($admin === null) {
                return null;
            }

            $nameSnapshot ??= $admin->name;
            $roleSnapshot ??= $admin->role;
        }

        $attributes['admin_user_id'] = $adminUserId;
        $attributes['admin_name']    = $nameSnapshot;
        $attributes['admin_role']    = $roleSnapshot;

        if (empty($attributes['metadata'])) {
            $attributes['metadata'] = null;
        }

        try {
            return StaffActivity::updateOrCreate(
                [
                    'source_type' => $attributes['source_type'],
                    'source_id'   => $attributes['source_id'],
                    'action'      => $attributes['action'],
                ],
                $attributes
            );
        } catch (\Throwable $e) {
            // Rule 3.
            Log::warning('Staff activity could not be recorded', [
                'source' => ($attributes['source_type'] ?? '?') . ':' . ($attributes['source_id'] ?? '?'),
                'action' => $attributes['action'] ?? null,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The admin behind the current request, when the source row does not name
     * one itself.
     *
     * Returns null in queued jobs, scheduled commands and webhooks, which is
     * the intent — there is no person there to credit.
     */
    private function currentAdminId(): ?int
    {
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof AdminUser ? $user->id : null;
    }

    /** @var array<int, AdminUser|null> */
    private array $adminCache = [];

    /**
     * Memoised per process. A backfill walks tens of thousands of rows written
     * by a handful of people; looking each one up again would make the command
     * slower than the history it is reading.
     */
    private function admin(int $id): ?AdminUser
    {
        return $this->adminCache[$id] ??= AdminUser::find($id);
    }

    // -------------------------------------------------------------------------

    /**
     * Order-log actions worth surfacing as headline work, used by the summary
     * to answer "what did this person actually do" rather than "how many rows
     * did they generate".
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return StaffActivity::CATEGORIES;
    }

    /**
     * Which category an order-log action belongs to. Exposed for the backfill
     * command's dry run, so a survey can report the split before writing
     * anything.
     */
    public static function categoryForOrderLogAction(string $action): string
    {
        return self::ORDER_LOG_CATEGORIES[$action] ?? 'orders';
    }

    /** @return array<int, string> */
    public static function customerActions(): array
    {
        return self::CUSTOMER_ACTIONS;
    }

    /**
     * Whether an order this log belongs to still exists. Used only by the
     * backfill, which reads rows whose orders may since have been deleted.
     */
    public static function orderExists(?int $orderId): bool
    {
        return $orderId !== null && Order::whereKey($orderId)->exists();
    }
}
