<?php

namespace App\Console\Commands;

use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CrmFollowUpsDigest extends Command
{
    protected $signature = 'crm:follow-ups-digest {--dry-run : Show summary without logging}';

    protected $description = 'Log (and optionally email assigned admins about) overdue CRM follow-ups';

    private const CLOSED_STATUSES = ['converted', 'closed', 'spam', 'rejected'];

    public function handle(): int
    {
        $now     = now();
        $dryRun  = $this->option('dry-run');

        // Count overdue follow-ups
        $overdue = QuoteRequest::whereNotNull('follow_up_at')
            ->where('follow_up_at', '<', $now)
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->count();

        $dueToday = QuoteRequest::whereNotNull('follow_up_at')
            ->whereDate('follow_up_at', $now->toDateString())
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->count();

        // Group overdue by assigned admin
        $byAdmin = QuoteRequest::whereNotNull('follow_up_at')
            ->where('follow_up_at', '<', $now)
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('COUNT(*) as count'))
            ->groupBy('assigned_to')
            ->get();

        $unassigned = QuoteRequest::whereNotNull('follow_up_at')
            ->where('follow_up_at', '<', $now)
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->whereNull('assigned_to')
            ->count();

        $summary = [
            'overdue'         => $overdue,
            'due_today'       => $dueToday,
            'unassigned'      => $unassigned,
            'by_assigned_to'  => $byAdmin->map(fn ($r) => ['admin_id' => $r->assigned_to, 'count' => $r->count])->values(),
            'run_at'          => $now->toIso8601String(),
        ];

        if ($dryRun) {
            $this->info("[dry-run] CRM Follow-up Digest:");
            $this->line("  Overdue:   {$overdue}");
            $this->line("  Due today: {$dueToday}");
            $this->line("  Unassigned overdue: {$unassigned}");
            return self::SUCCESS;
        }

        // Log structured digest
        Log::info('[crm_followup_digest] Daily CRM follow-up digest', $summary);

        // Log a system communication entry for audit trail
        if ($overdue > 0 || $dueToday > 0) {
            CustomerCommunication::create([
                'type'         => 'system',
                'direction'    => 'internal',
                'subject'      => "CRM Digest: {$overdue} overdue, {$dueToday} due today",
                'body'         => json_encode($summary),
                'status'       => 'completed',
                'completed_at' => $now,
                'metadata'     => $summary,
            ]);
        }

        // Email digest to CRM_DIGEST_EMAIL if configured (not customer emails)
        $digestEmail = config('mail.crm_digest_email');
        if ($digestEmail && ($overdue > 0 || $dueToday > 0)) {
            try {
                Mail::raw(
                    "CRM Follow-up Digest — {$now->toDateString()}\n\n" .
                    "Overdue follow-ups: {$overdue}\n" .
                    "Due today: {$dueToday}\n" .
                    "Unassigned overdue: {$unassigned}\n\n" .
                    "Review: /admin/crm/follow-ups?due=overdue",
                    function ($m) use ($digestEmail, $now) {
                        $m->to($digestEmail)->subject("CRM Digest: Follow-ups — {$now->toDateString()}");
                    }
                );
                Log::info('[crm_digest_email_sent] CRM digest emailed', ['to' => $digestEmail]);
            } catch (\Throwable $e) {
                Log::warning('[crm_digest_email_failed] CRM digest email failed', ['error' => $e->getMessage()]);
            }
        }

        $this->info("CRM digest logged. Overdue: {$overdue}, Due today: {$dueToday}.");

        return self::SUCCESS;
    }
}
