<?php

namespace App\Console\Commands;

use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use Illuminate\Console\Command;

/**
 * CRM-3B — create in-app notifications for due/overdue lead follow-ups.
 *
 * Runs hourly. For each assigned, still-open quote whose follow_up_at has
 * arrived and that is not yet completed, the assigned owner gets a
 * `follow_up_due` notification. Dedupe is keyed on quote + due-date so the
 * owner is reminded at most once per scheduled follow-up date (not every run).
 *
 * No customer emails are sent.
 */
class NotifyDueFollowUps extends Command
{
    protected $signature = 'admin:notifications:due-followups {--dry-run : Report counts without creating notifications}';

    protected $description = 'Notify assigned admins of due/overdue lead follow-ups (CRM-3B)';

    private const CLOSED_STATUSES = ['converted', 'closed', 'spam', 'rejected'];

    public function handle(): int
    {
        $now    = now();
        $dryRun = $this->option('dry-run');

        $quotes = QuoteRequest::whereNotNull('assigned_to')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $now)
            ->whereNull('follow_up_completed_at')
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->get();

        if ($dryRun) {
            $this->info("[dry-run] {$quotes->count()} due/overdue follow-up(s) would be notified.");
            return self::SUCCESS;
        }

        $created = 0;

        foreach ($quotes as $quote) {
            $dueDate  = $quote->follow_up_at->toDateString();
            $overdue  = $quote->follow_up_at->lt($now->copy()->startOfDay());
            $who      = $quote->company_name ?: $quote->full_name;

            $notification = AdminNotificationService::notifyUser(
                adminUserId: (int) $quote->assigned_to,
                type:        'follow_up_due',
                title:       $overdue ? 'Follow-up overdue' : 'Follow-up due today',
                body:        sprintf('Follow-up for %s (%s) is due.', $who, $quote->ref_number),
                actionUrl:   "/admin/quotes/{$quote->id}",
                severity:    $overdue ? 'urgent' : 'warning',
                relatedType: 'quote_request',
                relatedId:   $quote->id,
                metadata:    ['ref_number' => $quote->ref_number, 'due_date' => $dueDate],
                // One reminder per follow-up date, regardless of read state.
                dedupeKey:   "follow_up_due:quote_request:{$quote->id}:{$dueDate}",
                includeRead: true,
            );

            if ($notification) {
                $created++;
            }
        }

        $this->info("Due follow-up notifications created: {$created} (of {$quotes->count()} candidate quotes).");

        return self::SUCCESS;
    }
}
