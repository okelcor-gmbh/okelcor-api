<?php

namespace App\Console\Commands;

use App\Mail\StaffContributionDigest;
use App\Models\StaffActivity;
use App\Services\StaffReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails the team contribution report to the people who asked for it.
 *
 * Recipients come from `STAFF_DIGEST_RECIPIENTS`, deliberately rather than from
 * "everyone holding staff.view_team": a performance report landing in an inbox
 * nobody asked for it in is the fastest way to make a system like this feel like
 * surveillance rather than a record.
 *
 * Sends inline. `QUEUE_CONNECTION` is still `sync` on production and this is two
 * or three e-mails a month, not a campaign — queueing it would make the send
 * depend on infrastructure that is not there yet, for no benefit. The bulk
 * campaign job is the thing that genuinely needs the worker.
 */
class SendStaffContributionDigest extends Command
{
    protected $signature = 'staff:digest
        {--days= : Days to cover (defaults to config)}
        {--from= : Explicit start date, YYYY-MM-DD}
        {--to= : Explicit end date, YYYY-MM-DD}
        {--to-email=* : Override the configured recipients}
        {--dry-run : Print the report instead of sending it}';

    protected $description = 'E-mail the team contribution report to the configured recipients';

    public function handle(StaffReportService $reports): int
    {
        if (! StaffActivity::ledgerAvailable()) {
            $this->error('The contribution ledger is not available. Run `artisan migrate` first.');

            return self::FAILURE;
        }

        [$from, $to] = $this->range();

        $report = $reports->build($from, $to);

        if ($this->option('dry-run')) {
            $this->render($report);

            return self::SUCCESS;
        }

        if (! config('staff.digest.enabled', true)) {
            $this->warn('STAFF_DIGEST_ENABLED is false — nothing sent.');

            return self::SUCCESS;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            // Not an error. A server with nobody configured should say so
            // plainly on a scheduled run rather than failing every night.
            $this->warn('No recipients configured. Set STAFF_DIGEST_RECIPIENTS, or pass --to-email=.');

            return self::SUCCESS;
        }

        $subject = sprintf('Team contribution — %s to %s', $from, $to);
        $sent    = 0;

        foreach ($recipients as $address) {
            try {
                Mail::to($address)->send(new StaffContributionDigest(
                    report: $report,
                    emailSubject: $subject,
                    panelUrl: $this->panelUrl(),
                ));

                $this->line("  sent to {$address}");
                $sent++;
            } catch (\Throwable $e) {
                // One bad address must not stop the rest. Logged rather than
                // swallowed, because a digest nobody notices failing is a
                // digest nobody can rely on.
                $this->error("  failed for {$address}: {$e->getMessage()}");
                Log::warning('[staff_digest_failed]', ['to' => $address, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("{$sent} of " . count($recipients) . ' sent, covering ' . count($report['people']) . ' people.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        if ($this->option('from') && $this->option('to')) {
            return [
                Carbon::parse($this->option('from'))->toDateString(),
                Carbon::parse($this->option('to'))->toDateString(),
            ];
        }

        $days = (int) ($this->option('days') ?: config('staff.digest.days', 30));
        $days = max(1, $days);

        return [
            Carbon::today()->subDays($days - 1)->toDateString(),
            Carbon::today()->toDateString(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $override = (array) $this->option('to-email');

        if (array_filter($override) !== []) {
            return array_values(array_filter($override));
        }

        return (array) config('staff.digest.recipients', []);
    }

    private function panelUrl(): ?string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');

        return $base === '' ? null : $base . '/admin/contribution/team';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info("Team contribution — {$report['from']} to {$report['to']}");
        $this->newLine();

        $this->table(
            ['Person', 'Job', 'Recorded', 'Logged', 'Awaiting review'],
            array_map(fn ($p) => [
                $p['name'],
                $p['job_title'] . ($p['job_title_set'] ? '' : '  (from role)'),
                $p['recorded']['total'],
                $p['self_reported']['total'],
                $p['self_reported']['pending'] ?: '·',
            ], $report['people'])
        );

        $this->newLine();
        foreach ($report['caveats'] as $caveat) {
            $this->line('  · ' . $caveat);
        }

        $this->newLine();
        $recipients = $this->recipients();
        $this->line($recipients === []
            ? 'No recipients configured — a real run would send nothing.'
            : 'Would send to: ' . implode(', ', $recipients));
    }
}
