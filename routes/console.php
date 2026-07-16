<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

Schedule::command('tracking:sync-carriers')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/carrier-tracking-sync.log'));

// Inbound e-mail capture is push-based (a Cloudflare Email Worker calls
// POST /webhooks/email-inbound directly) — no polling job needed here,
// unlike the IMAP-based approach this replaced.
