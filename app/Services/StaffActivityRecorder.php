<?php

namespace App\Services;

use App\Models\AdminSecurityEvent;
use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\Claim;
use App\Models\CustomerCommunication;
use App\Models\EbayListingLog;
use App\Models\EcInvoiceGroup;
use App\Models\EcInvoiceLine;
use App\Models\EcInvoicePeriod;
use App\Models\FinanceInvoice;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use App\Models\LiquidityWeek;
use App\Models\Media;
use App\Models\Order;
use App\Models\OrderCostLine;
use App\Models\OrderLog;
use App\Models\OrderSignoff;
use App\Models\PartnerSaleAudit;
use App\Models\SalesOrderEntry;
use App\Models\StaffActivity;
use App\Models\Todo;
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
    // Finance's own working (Session 111)
    //
    // The ledger was built from the ORDER trail, and finance does most of its
    // work beside it — the snapshot board, the EC/ZM portal, the weekly
    // liquidity file, per-order cost lines. The result was a finance team with
    // a literally empty record: 0 rows each for both finance accounts while
    // 293 snapshot items and a whole ZM filing sat in their tables.
    // -------------------------------------------------------------------------

    /**
     * A record on the finance snapshot board — a payment to chase, a receipt
     * to collect. This is finance's daily working surface and the single
     * largest body of uncredited work found.
     */
    public function fromFinanceSnapshotItem(FinanceSnapshotItem $item): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'finance_snapshot_item_raised',
            'subject_type'  => 'finance_snapshot_item',
            'subject_id'    => $item->id,
            'subject_label' => trim((string) ($item->client ?: $item->ref ?: $item->category))
                ?: ('Snapshot item #' . $item->id),
            'source_type'   => 'finance_snapshot_item',
            'source_id'     => $item->id,
            'occurred_at'   => $item->created_at ?? now(),
            'metadata'      => array_filter([
                'category' => $item->category,
                'status'   => $item->status,
                'amount'   => $item->amount === null ? null : (string) $item->amount,
            ], fn ($v) => $v !== null && $v !== ''),
        ], $item->created_by);
    }

    /**
     * An after-sales claim. Two distinct pieces of work, written as two
     * idempotent rows: LOGGING the claim (pulling it out of the e-mail
     * thread and into the system) credits whoever created it, and DECIDING
     * it credits whoever approved or rejected it. Re-saves update in place —
     * the (source_type, source_id, action) key — so a claim edited ten times
     * still counts once per act.
     */
    public function fromClaim(Claim $claim): ?StaffActivity
    {
        $label = trim(($claim->ref ?: ('Claim #' . $claim->id))
            . ($claim->customer_name ? " — {$claim->customer_name}" : ''));

        $raised = $this->write([
            'category'      => 'support',
            'action'        => 'claim_logged',
            'subject_type'  => 'claim',
            'subject_id'    => $claim->id,
            'subject_label' => $label,
            'source_type'   => 'claim',
            'source_id'     => $claim->id,
            'occurred_at'   => $claim->created_at ?? now(),
            'metadata'      => array_filter([
                'type'   => $claim->type,
                'status' => $claim->status,
                'order'  => $claim->order_number,
            ], fn ($v) => $v !== null && $v !== ''),
        ], $claim->created_by);

        if ($claim->resolved_at !== null) {
            $this->write([
                'category'      => 'support',
                'action'        => 'claim_resolved',
                'subject_type'  => 'claim',
                'subject_id'    => $claim->id,
                'subject_label' => $label,
                'source_type'   => 'claim',
                'source_id'     => $claim->id,
                'occurred_at'   => $claim->resolved_at,
                'metadata'      => array_filter([
                    'type'    => $claim->type,
                    'outcome' => in_array($claim->status, Claim::RESOLVED_STATUSES, true) ? $claim->status : null,
                ]),
            ], $claim->resolved_by);
        }

        return $raised;
    }

    /** A country/VAT group opened on the EC Invoice List. */
    public function fromEcInvoiceGroup(EcInvoiceGroup $group): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'ec_invoice_group_opened',
            'subject_type'  => 'ec_invoice_group',
            'subject_id'    => $group->id,
            'subject_label' => trim(($group->country_code ?? '') . ' ' . ($group->customer_vat_id ?? ''))
                ?: ('EC group #' . $group->id),
            'source_type'   => 'ec_invoice_group',
            'source_id'     => $group->id,
            'occurred_at'   => $group->created_at ?? now(),
            'metadata'      => array_filter([
                'period'           => $group->period,
                'transaction_type' => $group->transaction_type,
            ]),
        ], $group->created_by);
    }

    /** One invoice logged onto a ZM group. */
    public function fromEcInvoiceLine(EcInvoiceLine $line): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'ec_invoice_line_logged',
            'subject_type'  => 'ec_invoice_line',
            'subject_id'    => $line->id,
            'subject_label' => $line->invoice_number ?: ('EC line #' . $line->id),
            'source_type'   => 'ec_invoice_line',
            'source_id'     => $line->id,
            'occurred_at'   => $line->created_at ?? now(),
            'metadata'      => array_filter([
                'amount' => $line->amount === null ? null : (string) $line->amount,
            ]),
        ], $line->created_by);
    }

    /**
     * A ZM period moved on — the § 18a filing itself.
     *
     * Attributed to `updated_by` and stamped at `updated_at`, because the work
     * being recorded is the submission, not the row first appearing.
     */
    public function fromEcInvoicePeriod(EcInvoicePeriod $period): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'ec_period_' . ($period->status ?: 'updated'),
            'subject_type'  => 'ec_invoice_period',
            'subject_id'    => $period->id,
            'subject_label' => 'ZM ' . ($period->period ?: ('#' . $period->id)),
            'source_type'   => 'ec_invoice_period',
            'source_id'     => $period->id,
            'occurred_at'   => $period->submitted_at ?? $period->updated_at ?? now(),
            'metadata'      => array_filter(['status' => $period->status]),
        ], $period->updated_by);
    }

    /** A week opened or closed on the liquidity board. */
    public function fromLiquidityWeek(LiquidityWeek $week): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'liquidity_week_updated',
            'subject_type'  => 'liquidity_week',
            'subject_id'    => $week->id,
            'subject_label' => (string) ($week->week_key ?? ('Week #' . $week->id)),
            'source_type'   => 'liquidity_week',
            'source_id'     => $week->id,
            'occurred_at'   => $week->updated_at ?? now(),
            'metadata'      => [],
        ], $week->updated_by);
    }

    /**
     * A line on the weekly liquidity file.
     *
     * `created_by` arrives in the same session that wires this up — the table
     * shipped without any attribution at all, so its 66 existing rows can
     * never be credited to anyone. Guarded so the column can lag the code.
     */
    public function fromLiquidityEntry(FinanceLiquidityEntry $entry): ?StaffActivity
    {
        if (! FinanceLiquidityEntry::supportsAttribution()) {
            return null;
        }

        return $this->write([
            'category'      => 'finance',
            'action'        => 'liquidity_entry_recorded',
            'subject_type'  => 'finance_liquidity_entry',
            'subject_id'    => $entry->id,
            'subject_label' => trim((string) ($entry->description ?: $entry->supplier ?: $entry->line))
                ?: ('Liquidity entry #' . $entry->id),
            'source_type'   => 'finance_liquidity_entry',
            'source_id'     => $entry->id,
            'occurred_at'   => $entry->created_at ?? now(),
            'metadata'      => array_filter([
                'line'     => $entry->line,
                'week_key' => $entry->week_key,
            ]),
        ], $entry->created_by);
    }

    /** A cost booked against an order, for the profitability figure. */
    public function fromOrderCostLine(OrderCostLine $line): ?StaffActivity
    {
        return $this->write([
            'category'      => 'finance',
            'action'        => 'order_cost_recorded',
            'subject_type'  => 'order_cost_line',
            'subject_id'    => $line->id,
            'subject_label' => trim((string) ($line->order_ref ?: $line->supplier ?: $line->category))
                ?: ('Cost line #' . $line->id),
            'source_type'   => 'order_cost_line',
            'source_id'     => $line->id,
            'occurred_at'   => $line->created_at ?? now(),
            'metadata'      => array_filter([
                'kind'     => $line->kind,
                'category' => $line->category,
            ]),
        ], $line->entered_by);
    }

    /** A deal logged on the Sales & Order Management board. */
    public function fromSalesOrderEntry(SalesOrderEntry $entry): ?StaffActivity
    {
        return $this->write([
            'category'      => 'sales',
            'action'        => 'sales_order_logged',
            'subject_type'  => 'sales_order_entry',
            'subject_id'    => $entry->id,
            'subject_label' => trim((string) ($entry->order_no ?: $entry->customer_name))
                ?: ('Sales entry #' . $entry->id),
            'source_type'   => 'sales_order_entry',
            'source_id'     => $entry->id,
            'occurred_at'   => $entry->created_at ?? now(),
            'metadata'      => array_filter([
                'segment' => $entry->segment,
                'period'  => $entry->period,
            ]),
        ], $entry->created_by);
    }

    /**
     * A shared to-do that somebody FINISHED.
     *
     * Completion, not creation, and this is the whole point. Raising a to-do
     * asks for work; finishing one is the work. Crediting creation would also
     * have made the report actively wrong: one finance user raised 91 to-dos
     * in two hours, nearly all of them accidental duplicates of a single
     * request, and every one would have counted as a contribution.
     *
     * Categorised by the department that RAISED it, so finishing finance's
     * errand reads as finance work wherever the assignee happens to sit.
     */
    public function fromTodo(Todo $todo): ?StaffActivity
    {
        if ($todo->status !== 'done' || $todo->completed_by === null) {
            return null;
        }

        return $this->write([
            'category'      => self::DEPARTMENT_CATEGORIES[$todo->department()] ?? 'orders',
            'action'        => 'todo_completed',
            'subject_type'  => 'todo',
            'subject_id'    => $todo->id,
            'subject_label' => $todo->title,
            'source_type'   => 'todo',
            'source_id'     => $todo->id,
            'occurred_at'   => $todo->completed_at ?? $todo->updated_at ?? now(),
            'metadata'      => array_filter([
                'raised_by_department' => $todo->department(),
            ]),
        ], $todo->completed_by);
    }

    /**
     * Which ledger category a department's work belongs to.
     *
     * The two vocabularies were built for different jobs — departments name
     * who people are, categories name what the work is — so the mapping is
     * explicit rather than assumed to line up.
     */
    private const DEPARTMENT_CATEGORIES = [
        'Finance'    => 'finance',
        'Sales'      => 'sales',
        'Marketing'  => 'marketing',
        'Operations' => 'orders',
        'Support'    => 'support',
        'Content'    => 'marketing',
        'Management' => 'orders',
        'General'    => 'orders',
    ];

    /**
     * Security-event types that are somebody doing administrative work.
     *
     * A whitelist rather than a blocklist, because most of this table is not
     * work at all: logins, 2FA challenges and permission denials describe
     * presence and accidents, and counting them would put exactly the thing
     * this ledger refuses to measure back in through a side door.
     */
    private const SYSTEM_EVENT_TYPES = [
        'admin_created',
        'admin_deleted',
        'role_changed',
    ];

    /**
     * An upload. Attributed through `media.uploaded_by`, which has been filled
     * since the media library shipped.
     */
    public function fromMedia(Media $media): ?StaffActivity
    {
        return $this->write([
            'category'      => 'system',
            'action'        => 'media_uploaded',
            'subject_type'  => 'media',
            'subject_id'    => $media->id,
            'subject_label' => $media->original_filename ?: ($media->filename ?: ('Media #' . $media->id)),
            'source_type'   => 'media',
            'source_id'     => $media->id,
            'occurred_at'   => $media->created_at ?? now(),
            'metadata'      => array_filter(['mime' => $media->mime_type]),
        ], $media->uploaded_by);
    }

    public function fromEbayListingLog(EbayListingLog $log): ?StaffActivity
    {
        return $this->write([
            'category'      => 'system',
            'action'        => 'ebay_' . $log->action,
            'subject_type'  => 'product',
            'subject_id'    => $log->product_id,
            'subject_label' => $log->sku ?: ('Product #' . $log->product_id),
            'source_type'   => 'ebay_listing_log',
            'source_id'     => $log->id,
            'occurred_at'   => $log->created_at ?? now(),
            'metadata'      => array_filter(['status' => $log->status]),
        ], $log->admin_user_id);
    }

    /**
     * Administering the system itself — creating an account, changing somebody's
     * role. Only the whitelisted types above; the rest of this table is presence
     * data.
     */
    public function fromSecurityEvent(AdminSecurityEvent $event): ?StaffActivity
    {
        if (! in_array($event->type, self::SYSTEM_EVENT_TYPES, true)) {
            return null;
        }

        return $this->write([
            'category'      => 'system',
            'action'        => $event->type,
            'subject_type'  => 'admin_user',
            'subject_id'    => null,
            'subject_label' => $event->description ?: $event->type,
            'source_type'   => 'admin_security_event',
            'source_id'     => $event->id,
            'occurred_at'   => $event->created_at ?? now(),
            'metadata'      => [],
        ], $event->admin_id);
    }

    /**
     * A commit.
     *
     * Development has a system of record too — it simply is not this database.
     * Everything else in the ledger is read from a trail the API already keeps;
     * this reads the one git keeps, on exactly the same terms: attributed to a
     * person, idempotent, and never invented.
     *
     * `source_id` is derived from the sha rather than being a row id, because
     * git has no integer key and the ledger's idempotency index needs one. The
     * first 15 hex digits give 60 bits, which is far more than enough to keep
     * a repository's history distinct, and the full sha travels in
     * `subject_label` and `metadata` so a row can always be traced back to the
     * commit it describes.
     *
     * @param  array{sha: string, email: string, name: string, date: string, subject: string, repo: string, files?: int, insertions?: int, deletions?: int}  $commit
     */
    public function fromGitCommit(array $commit, ?int $adminUserId): ?StaffActivity
    {
        $sha = trim($commit['sha']);

        if ($sha === '' || $adminUserId === null) {
            return null;
        }

        return $this->write([
            'category'      => 'development',
            'action'        => 'code_committed',
            'subject_type'  => 'commit',
            'subject_id'    => null,
            'subject_label' => substr($sha, 0, 9) . ' — ' . mb_substr($commit['subject'] ?? '', 0, 120),
            'source_type'   => 'git_commit',
            'source_id'     => hexdec(substr($sha, 0, 15)),
            'occurred_at'   => $commit['date'],
            'metadata'      => array_filter([
                'sha'        => $sha,
                'repo'       => $commit['repo'] ?? null,
                'subject'    => $commit['subject'] ?? null,
                'files'      => $commit['files'] ?? null,
                'insertions' => $commit['insertions'] ?? null,
                'deletions'  => $commit['deletions'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ], $adminUserId);
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

        $admin = $this->admin($adminUserId);

        if ($admin === null && ($nameSnapshot === null || $roleSnapshot === null)) {
            return null;
        }

        $nameSnapshot ??= $admin?->name;
        $roleSnapshot ??= $admin?->role;

        $attributes['admin_user_id'] = $adminUserId;
        $attributes['admin_name']    = $nameSnapshot;
        $attributes['admin_role']    = $roleSnapshot;

        // The job, not the permission set. Snapshotted for the same reason the
        // name is: what someone did last quarter is a statement about who they
        // were then, and reading it live would relabel their history the day
        // they change job. Guarded, because this column ships one migration
        // after the table it sits on.
        if ($admin !== null && self::jobTitleAvailable()) {
            $attributes['admin_job_title'] = $admin->jobTitle();
        }

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
     * Whether the ledger carries a job-title column yet. Memoised per process,
     * same deploy-order rule as everything else here: recording must keep
     * working between the two migrations.
     */
    private static ?bool $jobTitleReady = null;

    public static function jobTitleAvailable(): bool
    {
        return self::$jobTitleReady ??= \Illuminate\Support\Facades\Schema::hasTable('staff_activities')
            && \Illuminate\Support\Facades\Schema::hasColumn('staff_activities', 'admin_job_title');
    }

    /** Test seam — the harness builds the column after the container boots. */
    public static function forgetJobTitleCheck(): void
    {
        self::$jobTitleReady = null;
    }

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
