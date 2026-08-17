<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One piece of work this system watched happen, attributed to the person who
 * did it.
 *
 * Business events only. There is no row here for a page view, a login, a
 * session length or a click — presence is not contribution, and a system that
 * measures it rewards whoever leaves the tab open. Every row names something
 * that reached an outcome and points at the record it happened to.
 *
 * Append-only in practice: rows are written by StaffActivityRecorder from model
 * events and there is no endpoint that edits or deletes one.
 */
class StaffActivity extends Model
{
    /**
     * The areas of work, chosen to match how the business is actually divided
     * rather than how the database is. A person reading their own month wants
     * to see "documents" and "support", not "trade_documents" and
     * "customer_communications".
     */
    public const CATEGORIES = [
        'orders',
        'documents',
        'finance',
        'sales',
        'marketing',
        'support',
        'partners',
    ];

    public const CATEGORY_LABELS = [
        'orders'    => 'Orders',
        'documents' => 'Trade documents',
        'finance'   => 'Finance',
        'sales'     => 'Sales & quotes',
        'marketing' => 'Marketing',
        'support'   => 'Customer support',
        'partners'  => 'Partner sales',
    ];

    protected $fillable = [
        'admin_user_id',
        'admin_name',
        'admin_role',
        'admin_job_title',
        'category',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'source_type',
        'source_id',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata'    => 'array',
        'subject_id'  => 'integer',
        'source_id'   => 'integer',
    ];

    /**
     * Whether the ledger exists yet.
     *
     * Memoised per process. Recording runs off model events that sit on the
     * order and invoice paths, so between this code deploying and the migration
     * running, confirming an order has to keep working exactly as before. A
     * reporting table must never be able to fail the thing it reports on —
     * the same rule InvoiceRegistrar follows.
     */
    private static ?bool $ready = null;

    public static function ledgerAvailable(): bool
    {
        return self::$ready ??= Schema::hasTable('staff_activities');
    }

    /** Test seam — the harness builds the table after the container boots. */
    public static function forgetLedgerCheck(): void
    {
        self::$ready = null;
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function scopeForAdmin(Builder $query, int $adminUserId): Builder
    {
        return $query->where('admin_user_id', $adminUserId);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ucfirst($this->category);
    }

    /**
     * The action as a person would say it: `document_sent` → "Document sent".
     * Deliberately derived rather than stored — the action vocabulary grows
     * with every feature, and a label table would be one more thing to forget
     * to update.
     */
    public function actionLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
    }
}
