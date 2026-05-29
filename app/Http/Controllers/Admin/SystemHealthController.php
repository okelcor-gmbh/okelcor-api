<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSecurityEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class SystemHealthController extends Controller
{
    // ── GET /api/v1/admin/system/health ──────────────────────────────────────

    public function index(): JsonResponse
    {
        $groups = [
            'application'   => $this->checkApplication(),
            'database'      => $this->checkDatabase(),
            'backups'       => $this->checkBackups(),
            'mail'          => $this->checkMail(),
            'security'      => $this->checkSecurity(),
            'inquiries'     => $this->checkInquiryQueue(),
            'data_quality'  => $this->checkDataQuality(),
            'crm'           => $this->checkCrmPipeline(),
            'endpoints'     => $this->checkEndpoints(),
        ];

        $all = collect($groups)->flatten(1);

        $criticalFail = $all->where('status', 'fail')->where('severity', 'critical')->count();
        $failCount    = $all->where('status', 'fail')->count();
        $warnCount    = $all->where('status', 'warning')->count();
        $passCount    = $all->where('status', 'pass')->count();

        $overall = match (true) {
            $criticalFail > 0 => 'critical',
            $failCount > 0    => 'fail',
            $warnCount > 0    => 'warning',
            default           => 'pass',
        };

        $snapshot = [
            'overall'      => $overall,
            'summary'      => [
                'pass'     => $passCount,
                'warning'  => $warnCount,
                'fail'     => $failCount,
                'critical' => $criticalFail,
                'total'    => $all->count(),
            ],
            'groups'       => $groups,
            'generated_at' => now()->toIso8601String(),
        ];

        // Cache the snapshot for the hourly monitor
        Cache::put('system_health_snapshot', $snapshot, now()->addMinutes(90));

        return response()->json([
            'data'    => $snapshot,
            'message' => 'System health check complete.',
        ]);
    }

    // ── GET /api/v1/admin/system/errors ──────────────────────────────────────

    public function errors(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);

        $securityEvents = AdminSecurityEvent::whereIn('severity', ['warning', 'critical'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($e) => [
                'source'    => 'security_event',
                'timestamp' => $e->created_at?->toIso8601String(),
                'level'     => $e->severity,
                'type'      => $e->type,
                'message'   => $e->description,
                'admin'     => $e->admin_email,
                'ip'        => $e->ip_address,
                'metadata'  => $e->metadata,
                'fix_hint'  => null,
            ]);

        $failedJobs = collect();
        try {
            $failedJobs = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(20)
                ->get()
                ->map(fn ($j) => [
                    'source'    => 'failed_job',
                    'timestamp' => $j->failed_at,
                    'level'     => 'critical',
                    'type'      => 'queue_job_failed',
                    'message'   => mb_substr((string) ($j->exception ?? 'No exception recorded'), 0, 300),
                    'admin'     => null,
                    'ip'        => null,
                    'metadata'  => ['queue' => $j->queue ?? null, 'uuid' => $j->uuid ?? null],
                    'fix_hint'  => 'Run: php artisan queue:retry all',
                ]);
        } catch (\Throwable) {}

        $logErrors = $this->parseRecentLogErrors();

        $merged = $securityEvents
            ->concat($failedJobs)
            ->concat($logErrors)
            ->sortByDesc('timestamp')
            ->values()
            ->take($limit);

        return response()->json([
            'data'    => $merged,
            'meta'    => ['count' => $merged->count()],
            'message' => 'success',
        ]);
    }

    // ── Check groups ──────────────────────────────────────────────────────────

    /** @return array<int, array> */
    public function checkApplication(): array
    {
        return [
            $this->check('app_env', 'App Environment', fn () => [
                'status'   => config('app.env') === 'production' ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => 'APP_ENV = ' . config('app.env'),
                'fix_hint' => config('app.env') !== 'production' ? 'Set APP_ENV=production in .env' : null,
            ]),
            $this->check('app_debug', 'Debug Mode Off', fn () => [
                'status'   => config('app.debug') ? 'fail' : 'pass',
                'severity' => 'critical',
                'message'  => config('app.debug') ? 'APP_DEBUG is true — stack traces are exposed to clients' : 'APP_DEBUG = false',
                'fix_hint' => config('app.debug') ? 'Set APP_DEBUG=false in .env immediately' : null,
            ]),
            $this->check('app_url', 'App URL Configured', fn () => [
                'status'   => (config('app.url') && ! str_contains(config('app.url', ''), 'localhost')) ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => 'APP_URL = ' . config('app.url'),
                'fix_hint' => str_contains(config('app.url', ''), 'localhost') ? 'Set APP_URL to the production API domain' : null,
            ]),
            $this->check('php_version', 'PHP Version', fn () => [
                'status'   => version_compare(PHP_VERSION, '8.2', '>=') ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => 'PHP ' . PHP_VERSION,
                'fix_hint' => version_compare(PHP_VERSION, '8.2', '<') ? 'Upgrade PHP to 8.2+' : null,
            ]),
            $this->check('laravel_version', 'Laravel Version', fn () => [
                'status'   => 'pass',
                'severity' => 'low',
                'message'  => 'Laravel ' . app()->version(),
                'fix_hint' => null,
            ]),
            $this->check('storage_writable', 'Storage Writable', function () {
                $paths  = ['storage/app', 'storage/framework/cache', 'storage/logs'];
                $failed = array_filter($paths, fn ($p) => ! is_writable(base_path($p)));
                return [
                    'status'   => empty($failed) ? 'pass' : 'fail',
                    'severity' => 'high',
                    'message'  => empty($failed)
                        ? 'All storage paths are writable'
                        : 'Not writable: ' . implode(', ', $failed),
                    'fix_hint' => ! empty($failed)
                        ? 'chmod -R 775 storage/ && chown -R www-data:www-data storage/' : null,
                ];
            }),
            $this->check('cache_working', 'Cache Operational', function () {
                $key = '_health_' . uniqid();
                Cache::put($key, 'ok', 10);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);
                return [
                    'status'   => $ok ? 'pass' : 'fail',
                    'severity' => 'high',
                    'message'  => $ok ? 'Cache read/write OK' : 'Cache read/write failed',
                    'fix_hint' => ! $ok ? 'Check CACHE_DRIVER and storage/framework/cache permissions' : null,
                ];
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkDatabase(): array
    {
        return [
            $this->check('db_connection', 'Database Connection', function () {
                DB::connection()->getPdo();
                return [
                    'status'   => 'pass',
                    'severity' => 'critical',
                    'message'  => 'Connected to ' . config('database.connections.mysql.database'),
                    'fix_hint' => null,
                ];
            }),
            $this->check('db_migrations', 'Migrations Up to Date', function () {
                $files  = count(glob(database_path('migrations/*.php')) ?: []);
                $ran    = DB::table('migrations')->count();
                $diff   = $files - $ran;
                return [
                    'status'   => $diff <= 0 ? 'pass' : 'warning',
                    'severity' => 'high',
                    'message'  => $diff <= 0
                        ? "{$ran} migrations applied"
                        : "{$diff} pending migration(s) ({$ran}/{$files} applied)",
                    'fix_hint' => $diff > 0 ? 'Run: php artisan migrate --force' : null,
                ];
            }),
            $this->check('failed_jobs', 'Failed Jobs', function () {
                $count = DB::table('failed_jobs')->count();
                return [
                    'status'   => match (true) {
                        $count === 0  => 'pass',
                        $count < 5    => 'warning',
                        default       => 'fail',
                    },
                    'severity' => 'medium',
                    'message'  => $count === 0 ? 'No failed jobs' : "{$count} failed job(s) in queue",
                    'fix_hint' => $count > 0 ? 'Inspect with: php artisan queue:failed — then retry or flush' : null,
                ];
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkBackups(): array
    {
        return [
            $this->check('backup_enabled', 'Backup Enabled', fn () => [
                'status'   => config('backup.enabled', false) ? 'pass' : 'warning',
                'severity' => 'high',
                'message'  => config('backup.enabled', false) ? 'BACKUP_ENABLED = true' : 'Backups disabled (BACKUP_ENABLED=false)',
                'fix_hint' => ! config('backup.enabled', false) ? 'Set BACKUP_ENABLED=true in .env' : null,
            ]),
            $this->check('last_backup', 'Last Backup Age', function () {
                $dir   = storage_path('app/backups');
                $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: []) : [];

                if (empty($files)) {
                    return [
                        'status'   => 'warning',
                        'severity' => 'high',
                        'message'  => 'No backup archives found in storage/app/backups',
                        'fix_hint' => 'Run: php artisan backup:okelcor — then confirm cron is active',
                        'data'     => null,
                    ];
                }

                usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));
                $latest    = $files[0];
                $mtime     = filemtime($latest);
                $ageHours  = round((time() - $mtime) / 3600, 1);
                $sizeMb    = round(filesize($latest) / 1048576, 2);
                $tooOld    = $ageHours > 26;
                $filename  = basename($latest);
                $isFull    = str_contains($filename, '-full-');
                $createdAt = Carbon::createFromTimestamp($mtime)->toIso8601String();

                return [
                    'status'   => $tooOld ? 'warning' : 'pass',
                    'severity' => 'high',
                    'message'  => "{$filename} | {$sizeMb} MB | {$ageHours}h ago",
                    'fix_hint' => $tooOld ? 'Backup is over 26h old — verify cron (* * * * * php artisan schedule:run) is active' : null,
                    'data'     => [
                        'filename'      => $filename,
                        'size_mb'       => $sizeMb,
                        'age_hours'     => $ageHours,
                        'created_at'    => $createdAt,
                        'type'          => $isFull ? 'full' : 'daily',
                        'archive_count' => count($files),
                    ],
                ];
            }),
            $this->check('backup_scheduler', 'Backup Scheduler Registered', function () {
                $path       = base_path('routes/console.php');
                $configured = file_exists($path) && str_contains((string) file_get_contents($path), 'backup:okelcor');
                return [
                    'status'   => $configured ? 'pass' : 'warning',
                    'severity' => 'high',
                    'message'  => $configured
                        ? 'backup:okelcor scheduled in routes/console.php'
                        : 'No backup schedule found in routes/console.php',
                    'fix_hint' => ! $configured
                        ? "Add Schedule::command('backup:okelcor')->dailyAt('02:00') to routes/console.php" : null,
                ];
            }),
            $this->check('backup_retention', 'Backup Retention', function () {
                $dir    = storage_path('app/backups');
                $files  = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'okelcor-backup-*.zip') ?: []) : [];
                $retain = (int) config('backup.retention_days', 14);
                return [
                    'status'   => 'pass',
                    'severity' => 'low',
                    'message'  => count($files) . ' archive(s) stored — ' . $retain . '-day retention policy',
                    'fix_hint' => null,
                ];
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkMail(): array
    {
        return [
            $this->check('mail_driver', 'Mail Driver', fn () => [
                'status'   => (config('mail.default') && config('mail.default') !== 'log') ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => 'MAIL_MAILER = ' . (config('mail.default') ?? 'not set'),
                'fix_hint' => config('mail.default') === 'log'
                    ? 'MAIL_MAILER=log only writes to file — use smtp/ses/postmark for real delivery' : null,
            ]),
            $this->check('mail_from', 'Mail From Address', fn () => [
                'status'   => config('mail.from.address') ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => config('mail.from.address')
                    ? 'MAIL_FROM = ' . config('mail.from.address')
                    : 'MAIL_FROM_ADDRESS not configured',
                'fix_hint' => ! config('mail.from.address') ? 'Set MAIL_FROM_ADDRESS in .env' : null,
            ]),
            $this->check('mail_host', 'SMTP Host', fn () => [
                'status'   => config('mail.mailers.smtp.host') ? 'pass' : 'warning',
                'severity' => 'low',
                'message'  => config('mail.mailers.smtp.host') ? 'MAIL_HOST configured' : 'MAIL_HOST not set',
                'fix_hint' => ! config('mail.mailers.smtp.host') ? 'Set MAIL_HOST in .env' : null,
            ]),
            $this->check('quote_email', 'Quote Request Email', fn () => [
                'status'   => config('mail.quote_email') ? 'pass' : 'fail',
                'severity' => 'high',
                'message'  => config('mail.quote_email')
                    ? 'QUOTE_EMAIL = ' . config('mail.quote_email')
                    : 'QUOTE_EMAIL not set — admin will never receive quote notifications',
                'fix_hint' => ! config('mail.quote_email')
                    ? 'Set QUOTE_EMAIL=support@okelcor.com in .env' : null,
            ]),
            $this->check('order_email', 'Order Notification Email', fn () => [
                'status'   => config('mail.order_email') ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => config('mail.order_email')
                    ? 'ORDER_EMAIL = ' . config('mail.order_email')
                    : 'ORDER_EMAIL not set — admin will not receive order notifications',
                'fix_hint' => ! config('mail.order_email')
                    ? 'Set ORDER_EMAIL=support@okelcor.com in .env' : null,
            ]),
        ];
    }

    /** @return array<int, array> */
    public function checkSecurity(): array
    {
        return [
            $this->check('sec_debug', 'APP_DEBUG Off', fn () => [
                'status'   => config('app.debug') ? 'fail' : 'pass',
                'severity' => 'critical',
                'message'  => config('app.debug') ? 'APP_DEBUG=true — stack traces exposed' : 'APP_DEBUG = false',
                'fix_hint' => config('app.debug') ? 'Set APP_DEBUG=false immediately' : null,
            ]),
            $this->check('sec_app_key', 'APP_KEY Present', fn () => [
                'status'   => config('app.key') ? 'pass' : 'fail',
                'severity' => 'critical',
                'message'  => config('app.key') ? 'APP_KEY is set (value hidden)' : 'APP_KEY is missing',
                'fix_hint' => ! config('app.key') ? 'Run: php artisan key:generate' : null,
            ]),
            $this->check('sec_2fa', 'Admin 2FA Mandatory', function () {
                $grace    = config('auth.admin_2fa_grace_until');
                $inGrace  = $grace && now()->lt(Carbon::parse($grace)->endOfDay());
                return [
                    'status'   => $inGrace ? 'warning' : 'pass',
                    'severity' => 'high',
                    'message'  => $inGrace
                        ? "Grace period active until {$grace} — 2FA not yet enforced"
                        : '2FA is mandatory for all admin users',
                    'fix_hint' => $inGrace ? "Remove ADMIN_2FA_GRACE_UNTIL once all admins have enrolled" : null,
                ];
            }),
            $this->check('sec_session_ttl', 'Admin Session TTL', function () {
                $ttl = (int) config('auth.admin_session_ttl_minutes', 300);
                return [
                    'status'   => $ttl <= 300 ? 'pass' : 'warning',
                    'severity' => 'medium',
                    'message'  => "TTL = {$ttl} min (" . round($ttl / 60, 1) . "h)",
                    'fix_hint' => $ttl > 300 ? 'Set ADMIN_SESSION_TTL_MINUTES=300 or lower' : null,
                ];
            }),
            $this->check('sec_cors', 'CORS Origins Restricted', function () {
                $allowed = (array) config('cors.allowed_origins', []);
                $isWild  = in_array('*', $allowed, true);
                return [
                    'status'   => $isWild ? 'fail' : 'pass',
                    'severity' => 'critical',
                    'message'  => $isWild ? 'CORS allows * (all origins) — open to any domain' : 'CORS origins are restricted',
                    'fix_hint' => $isWild ? 'Set specific domains in config/cors.php allowed_origins' : null,
                ];
            }),
            $this->check('sec_stripe_webhook', 'Stripe Webhook Secret', fn () => [
                'status'   => config('services.stripe.webhook_secret') ? 'pass' : 'warning',
                'severity' => 'high',
                'message'  => config('services.stripe.webhook_secret')
                    ? 'STRIPE_WEBHOOK_SECRET is set (value hidden)'
                    : 'STRIPE_WEBHOOK_SECRET not configured',
                'fix_hint' => ! config('services.stripe.webhook_secret')
                    ? 'Set STRIPE_WEBHOOK_SECRET from Stripe Dashboard → Webhooks' : null,
            ]),
            $this->check('sec_ebay_secret', 'eBay Client Secret', fn () => [
                'status'   => config('services.ebay_sell.client_secret') ? 'pass' : 'warning',
                'severity' => 'medium',
                'message'  => config('services.ebay_sell.client_secret')
                    ? 'EBAY_CLIENT_SECRET is set (value hidden)'
                    : 'EBAY_CLIENT_SECRET not configured',
                'fix_hint' => ! config('services.ebay_sell.client_secret')
                    ? 'Set EBAY_CLIENT_SECRET in .env from the eBay Developer Portal' : null,
            ]),
            $this->check('sec_admins_without_2fa', 'All Admins Have 2FA', function () {
                $without = \App\Models\AdminUser::where('is_active', true)
                    ->whereNull('two_factor_confirmed_at')
                    ->count();
                return [
                    'status'   => $without === 0 ? 'pass' : 'warning',
                    'severity' => 'high',
                    'message'  => $without === 0
                        ? 'All active admins have 2FA enabled'
                        : "{$without} active admin(s) without 2FA",
                    'fix_hint' => $without > 0
                        ? 'Affected admins must log in to complete mandatory 2FA setup' : null,
                ];
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkInquiryQueue(): array
    {
        return [
            $this->check('pending_review_inquiries', 'Pending Review Inquiries', function () {
                try {
                    $count = DB::table('quote_requests')
                        ->where('review_status', 'needs_review')
                        ->count();

                    $status  = match (true) {
                        $count === 0 => 'pass',
                        $count < 10  => 'warning',
                        default      => 'fail',
                    };

                    return [
                        'status'   => $status,
                        'severity' => $count >= 10 ? 'medium' : 'low',
                        'message'  => $count === 0
                            ? 'No inquiries pending review'
                            : "{$count} inquiry/inquiries need admin review",
                        'fix_hint' => $count > 0
                            ? 'Review at GET /api/v1/admin/quote-requests?review_status=needs_review' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'warning', 'severity' => 'low', 'message' => 'Could not query inquiry queue'];
                }
            }),
            $this->check('spam_inquiries', 'Spam Inquiry Count', function () {
                try {
                    $count = DB::table('quote_requests')
                        ->where('review_status', 'spam')
                        ->whereDate('created_at', '>=', now()->subDays(7))
                        ->count();

                    return [
                        'status'   => $count > 20 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => "{$count} spam inquiry/inquiries in the last 7 days",
                        'fix_hint' => $count > 20 ? 'High spam volume — consider additional rate limiting' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Spam count unavailable'];
                }
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkDataQuality(): array
    {
        return [
            $this->check('duplicate_customers', 'Suspected Duplicate Customers', function () {
                try {
                    $count = DB::table('customers')
                        ->where('data_review_status', 'duplicate_suspected')
                        ->count();

                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'No suspected duplicate customers'
                            : "{$count} customer(s) flagged as possible duplicates",
                        'fix_hint' => $count > 0
                            ? 'Review at GET /api/v1/admin/customers/data-quality/issues?review_status=duplicate_suspected' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Duplicate check unavailable'];
                }
            }),
            $this->check('unscored_customers', 'Customers Without Quality Score', function () {
                try {
                    $count = DB::table('customers')->whereNull('data_quality_score')->count();
                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'All customers have quality scores'
                            : "{$count} customer(s) not yet scored",
                        'fix_hint' => $count > 0
                            ? 'Run: php artisan customers:recalculate-data-quality --all' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Score check unavailable'];
                }
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkCrmPipeline(): array
    {
        $closed = ['converted', 'closed', 'spam', 'rejected'];
        $now    = now();
        $today  = $now->toDateString();

        return [
            $this->check('crm_overdue_followups', 'Overdue Follow-ups', function () use ($now, $today, $closed) {
                try {
                    $count = DB::table('quote_requests')
                        ->whereNotNull('follow_up_at')
                        ->where('follow_up_at', '<', $now)
                        ->whereDate('follow_up_at', '!=', $today)
                        ->whereNotIn('qualification_status', $closed)
                        ->count();

                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'No overdue follow-ups'
                            : "{$count} follow-up(s) are overdue",
                        'fix_hint' => $count > 0
                            ? 'Review at GET /api/v1/admin/crm/follow-ups?due=overdue' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Follow-up check unavailable'];
                }
            }),
            $this->check('crm_due_today', 'Follow-ups Due Today', function () use ($today, $closed) {
                try {
                    $count = DB::table('quote_requests')
                        ->whereNotNull('follow_up_at')
                        ->whereDate('follow_up_at', $today)
                        ->whereNotIn('qualification_status', $closed)
                        ->count();

                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'No follow-ups due today'
                            : "{$count} follow-up(s) due today",
                        'fix_hint' => $count > 0
                            ? 'Review at GET /api/v1/admin/crm/follow-ups?due=today' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Due-today check unavailable'];
                }
            }),
            $this->check('crm_unassigned_qualified', 'Unassigned Qualified Leads', function () use ($closed) {
                try {
                    $count = DB::table('quote_requests')
                        ->where('qualification_status', 'qualified')
                        ->whereNull('assigned_to')
                        ->count();

                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'All qualified leads are assigned'
                            : "{$count} qualified lead(s) are unassigned",
                        'fix_hint' => $count > 0
                            ? 'Review at GET /api/v1/admin/quote-requests?qualification_status=qualified&unassigned=true' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Unassigned check unavailable'];
                }
            }),
            $this->check('crm_failed_emails', 'Failed CRM Emails (7 days)', function () {
                try {
                    $count = DB::table('customer_communications')
                        ->where('type', 'email')
                        ->where('status', 'failed')
                        ->where('created_at', '>=', now()->subDays(7))
                        ->count();

                    return [
                        'status'   => $count > 0 ? 'warning' : 'pass',
                        'severity' => 'low',
                        'message'  => $count === 0
                            ? 'No failed CRM emails in the last 7 days'
                            : "{$count} CRM email(s) failed in the last 7 days",
                        'fix_hint' => $count > 0 ? 'Check MAIL_MAILER + SMTP credentials' : null,
                    ];
                } catch (\Throwable) {
                    return ['status' => 'pass', 'severity' => 'low', 'message' => 'Failed email check unavailable'];
                }
            }),
        ];
    }

    /** @return array<int, array> */
    public function checkEndpoints(): array
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $hasRoute = function (string $uri, string $method = 'GET') use ($routes): bool {
            return $routes->contains(function ($r) use ($uri, $method) {
                return in_array(strtoupper($method), $r->methods(), true)
                    && $r->uri() === $uri;
            });
        };

        $endpoints = [
            ['key' => 'ep_admin_login',       'label' => 'Admin Login',           'uri' => 'api/v1/admin/login',                        'method' => 'POST'],
            ['key' => 'ep_admin_2fa',         'label' => 'Admin 2FA Verify',      'uri' => 'api/v1/admin/login/2fa',                    'method' => 'POST'],
            ['key' => 'ep_checkout',          'label' => 'Stripe Checkout',       'uri' => 'api/v1/payments/create-session',            'method' => 'POST'],
            ['key' => 'ep_stripe_webhook',    'label' => 'Stripe Webhook',        'uri' => 'api/v1/payments/webhook',                   'method' => 'POST'],
            ['key' => 'ep_trade_download',    'label' => 'Trade Doc Download',    'uri' => 'api/v1/auth/trade-documents/{id}/download', 'method' => 'GET'],
            ['key' => 'ep_admin_orders',      'label' => 'Admin Orders List',     'uri' => 'api/v1/admin/orders',                       'method' => 'GET'],
            ['key' => 'ep_ebay_readiness',    'label' => 'eBay Readiness',        'uri' => 'api/v1/admin/ebay/readiness',               'method' => 'GET'],
            ['key' => 'ep_logistics',         'label' => 'Logistics Dashboard',   'uri' => 'api/v1/admin/logistics/dashboard',          'method' => 'GET'],
            ['key' => 'ep_system_health',     'label' => 'System Health',         'uri' => 'api/v1/admin/system/health',                'method' => 'GET'],
            ['key' => 'ep_security_summary',  'label' => 'Security Summary',      'uri' => 'api/v1/admin/security/summary',             'method' => 'GET'],
        ];

        return array_map(function (array $ep) use ($hasRoute) {
            $exists = $hasRoute($ep['uri'], $ep['method']);
            return [
                'key'      => $ep['key'],
                'label'    => $ep['label'],
                'status'   => $exists ? 'pass' : 'fail',
                'severity' => 'critical',
                'message'  => $exists
                    ? "{$ep['method']} /{$ep['uri']} — registered"
                    : "{$ep['method']} /{$ep['uri']} — NOT FOUND",
                'fix_hint' => ! $exists ? 'Check routes/api.php — this route may have been removed' : null,
            ];
        }, $endpoints);
    }

    // ── Log parser ────────────────────────────────────────────────────────────

    private function parseRecentLogErrors(): \Illuminate\Support\Collection
    {
        $logFile = storage_path('logs/laravel.log');

        if (! file_exists($logFile)) {
            return collect();
        }

        try {
            $size    = filesize($logFile);
            $offset  = max(0, $size - 65536); // last 64 KB
            $fh      = fopen($logFile, 'r');
            fseek($fh, $offset);
            $content = fread($fh, 65536);
            fclose($fh);

            $entries = collect();
            // Match log entries: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message
            preg_match_all(
                '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \S+\.(ERROR|CRITICAL): (.+?)(?=\n\[|\z)/ms',
                $content,
                $matches,
                PREG_SET_ORDER
            );

            foreach (array_slice($matches, -30) as $m) {
                // Keep only the first line; strip stack trace
                $summary = mb_substr(trim(strtok($m[3], "\n")), 0, 300);

                // Scrub common secret patterns — never expose values
                $summary = preg_replace(
                    '/(password|token|secret|key|bearer|authorization)["\s:=\']+\S+/i',
                    '$1=[REDACTED]',
                    $summary
                );

                $entries->push([
                    'source'    => 'laravel_log',
                    'timestamp' => $m[1],
                    'level'     => strtolower($m[2]),
                    'type'      => 'app_error',
                    'message'   => $summary,
                    'admin'     => null,
                    'ip'        => null,
                    'metadata'  => null,
                    'fix_hint'  => null,
                ]);
            }

            return $entries;
        } catch (\Throwable) {
            return collect();
        }
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function check(string $key, string $label, callable $fn): array
    {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $result = [
                'status'   => 'fail',
                'severity' => 'high',
                'message'  => 'Check threw: ' . $e->getMessage(),
                'fix_hint' => 'Investigate server config or DB connectivity.',
            ];
        }

        return ['key' => $key, 'label' => $label] + $result;
    }
}
