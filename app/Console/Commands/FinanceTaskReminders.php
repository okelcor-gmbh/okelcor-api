<?php

namespace App\Console\Commands;

use App\Mail\FinanceTaskDigest;
use App\Models\AdminNotification;
use App\Models\FinanceSnapshotItem;
use App\Services\AdminNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The daily finance task report — one per tagged staff member, not one per
 * record. Finance asked for exactly this: a person with twelve open items
 * gets a single panel notification and a single email listing all twelve,
 * because twelve separate pings is how notifications get ignored.
 *
 * Once per day per person (deduped on the panel notification; the email
 * rides the same gate), only while they have open tagged items.
 */
class FinanceTaskReminders extends Command
{
    protected $signature   = 'finance:remind-assignees';
    protected $description = 'Send each tagged staff member one digest of all their open finance tasks (panel + email)';

    public function handle(): int
    {
        $today    = now()->toDateString();
        $startDay = now()->startOfDay();
        $sent     = 0;

        $byAssignee = FinanceSnapshotItem::with('assignee:id,name,display_name,email,is_active')
            ->whereNotNull('assigned_admin_id')
            ->whereNotIn('status', FinanceSnapshotItem::CLOSED_STATUSES)
            ->orderByRaw('date IS NULL, date')
            ->get()
            ->groupBy('assigned_admin_id');

        foreach ($byAssignee as $adminId => $items) {
            $assignee = $items->first()->assignee;
            if (! $assignee || ! $assignee->is_active) {
                continue;
            }

            $tasks = $items->map(function (FinanceSnapshotItem $i) use ($startDay) {
                $overdueDays = $i->date && $i->date->lt($startDay)
                    ? (int) $i->date->diffInDays($startDay)
                    : 0;

                return [
                    'ref'          => $i->ref,
                    'category'     => $i->category,
                    'client'       => $i->client,
                    'amount'       => (float) $i->amount,
                    'date'         => $i->date?->format('d M Y'),
                    'status'       => $i->status,
                    'comment'      => $i->comment,
                    'overdue_days' => $overdueDays,
                ];
            })->values()->all();

            $summary = [
                'open'         => count($tasks),
                'overdue'      => count(array_filter($tasks, fn ($t) => $t['overdue_days'] > 0)),
                'due_today'    => $items->filter(fn ($i) => $i->date && $i->date->isSameDay($startDay))->count(),
                'total_amount' => round(array_sum(array_column($tasks, 'amount')), 2),
            ];

            $title = "Your finance tasks: {$summary['open']} open"
                . ($summary['overdue'] > 0 ? " — {$summary['overdue']} overdue" : '');

            // Once per person per day, gating BOTH channels together. Checked
            // explicitly here rather than through notifyUser's return value:
            // that also comes back null when a side channel (mobile push)
            // hiccups, and a push failure must not swallow the email.
            $alreadyToday = AdminNotification::forUser((int) $adminId)
                ->where('type', 'finance_task_digest')
                ->whereDate('created_at', $today)
                ->exists();
            if ($alreadyToday) {
                continue;
            }

            AdminNotificationService::notifyUser(
                adminUserId: (int) $adminId,
                type: 'finance_task_digest',
                title: $title,
                body: collect($tasks)->take(5)
                    ->map(fn ($t) => $t['ref'] . ($t['overdue_days'] > 0 ? " ({$t['overdue_days']}d overdue)" : ''))
                    ->implode(' · ') . (count($tasks) > 5 ? ' · …' : ''),
                actionUrl: '/admin/my-work',
                severity: $summary['overdue'] > 0 ? 'warning' : 'info',
                dedupeKey: "finance_task_digest:{$adminId}:{$today}",
                includeRead: true,
            );

            try {
                $panelUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/admin/my-work';
                Mail::to($assignee->email)->send(new FinanceTaskDigest(
                    recipientName: trim($assignee->display_name ?: $assignee->name),
                    tasks: $tasks,
                    summary: $summary,
                    panelUrl: $panelUrl,
                ));
            } catch (\Throwable $e) {
                // The panel notification stands; a mail failure is logged,
                // never fatal to the rest of the run.
                Log::warning('FinanceTaskDigest email failed', [
                    'admin_user_id' => $adminId,
                    'error'         => $e->getMessage(),
                ]);
            }

            $sent++;
        }

        $this->info("Finance task digests sent: {$sent}");

        return self::SUCCESS;
    }
}
