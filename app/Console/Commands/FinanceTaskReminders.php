<?php

namespace App\Console\Commands;

use App\Models\FinanceSnapshotItem;
use App\Services\AdminNotificationService;
use Illuminate\Console\Command;

/**
 * The reminder half of "communication and reminders is key": every morning,
 * each staff member with tagged finance records that are due today or
 * overdue gets one notification per record. The dedupe key carries the day,
 * so an ignored task nags again tomorrow — but never twice in one day.
 */
class FinanceTaskReminders extends Command
{
    protected $signature   = 'finance:remind-assignees';
    protected $description = 'Notify assignees of finance snapshot records due today or overdue';

    public function handle(): int
    {
        $today = now()->toDateString();
        $sent  = 0;

        FinanceSnapshotItem::whereNotNull('assigned_admin_id')
            ->whereNotIn('status', FinanceSnapshotItem::CLOSED_STATUSES)
            ->whereNotNull('date')
            ->whereDate('date', '<=', $today)
            ->orderBy('date')
            ->chunkById(100, function ($chunk) use ($today, &$sent) {
                foreach ($chunk as $item) {
                    $overdueDays = (int) $item->date->diffInDays(now()->startOfDay());
                    $when = $overdueDays === 0
                        ? 'due today'
                        : "overdue by {$overdueDays} day" . ($overdueDays === 1 ? '' : 's');

                    $created = AdminNotificationService::notifyUser(
                        adminUserId: $item->assigned_admin_id,
                        type: 'finance_task_reminder',
                        title: "Reminder: {$item->ref} is {$when}",
                        body: ucwords(strtolower($item->category))
                            . ($item->client ? " · {$item->client}" : '')
                            . ' · ' . number_format($item->amount, 2)
                            . ($item->comment ? " · {$item->comment}" : ''),
                        actionUrl: '/admin/my-work',
                        severity: $overdueDays > 0 ? 'warning' : 'info',
                        relatedType: 'finance_snapshot_item',
                        relatedId: $item->id,
                        // One reminder per item per day, whether or not
                        // yesterday's was read.
                        dedupeKey: "finance_task_reminder:{$item->id}:{$today}",
                        includeRead: true,
                    );

                    if ($created) {
                        $sent++;
                    }
                }
            });

        $this->info("Finance task reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
