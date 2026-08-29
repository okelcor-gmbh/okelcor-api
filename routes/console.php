<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Customer tokens expire after 7 days (Session 104) and admin tokens after
// 5 hours; expired rows are dead weight in personal_access_tokens until
// pruned. 48-hour retention so a just-expired token is still inspectable if
// an incident is being investigated.
Schedule::command('sanctum:prune-expired --hours=48')
    ->dailyAt('03:00')
    ->onOneServer();

Schedule::command('backup:okelcor')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-schedule.log'));

Schedule::command('system:health --snapshot')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/health-schedule.log'));

Schedule::command('ebay:sync-orders --days=30')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ebay-order-sync.log'));

Schedule::command('finance:remind-assignees')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/finance-reminders.log'));

Schedule::command('crm:follow-ups-digest')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-digest.log'));

Schedule::command('admin:notifications:due-followups')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/admin-notifications.log'));

// The team contribution report. Monthly rather than weekly on purpose: a
// performance summary arriving every Monday trains people to work for the
// report, and a month is the shortest window in which "sixty documents" and
// "one container negotiation that took three weeks" are both visible.
// Recipients come from STAFF_DIGEST_RECIPIENTS; with none set the command says
// so and sends nothing.
Schedule::command('staff:digest')
    ->monthlyOn(1, '07:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/staff-digest.log'));

Schedule::command('tracking:sync-carriers')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/carrier-tracking-sync.log'));

// Inbound e-mail capture is push-based (a Cloudflare Email Worker calls
// POST /webhooks/email-inbound directly) — no polling job needed here,
// unlike the IMAP-based approach this replaced.

// AI-generated admin dashboard insights — runs regardless of whether
// GEMINI_API_KEY is set (AdminInsightsService no-ops silently if it isn't).
// Every 15 minutes keeps well within Gemini's free-tier daily rate limit
// even with several admins viewing the dashboard, since generation is
// decoupled from page views entirely (see FRONTEND_NOTE_admin-insights.md).
Schedule::command('insights:generate')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/admin-insights.log'));
