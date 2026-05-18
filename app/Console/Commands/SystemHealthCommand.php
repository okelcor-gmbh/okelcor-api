<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\SystemHealthController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SystemHealthCommand extends Command
{
    protected $signature = 'system:health
                            {--snapshot : Store result in cache (used by the hourly schedule)}
                            {--group=   : Run only this check group (application|database|backups|mail|security|endpoints)}
                            {--errors   : Show recent errors instead of health checks}
                            {--limit=20 : Number of recent errors to show (--errors only)}';

    protected $description = 'Display a full system health report — mirrors GET /api/v1/admin/system/health';

    private array $statusColors = [
        'pass'     => 'green',
        'warning'  => 'yellow',
        'fail'     => 'red',
        'critical' => 'red',
    ];

    public function handle(): int
    {
        $controller = new SystemHealthController();

        if ($this->option('errors')) {
            $limit   = max(1, (int) ($this->option('limit') ?? 20));
            $request = Request::create('/admin/system/errors', 'GET', ['limit' => $limit]);
            return $this->showErrors($controller, $request);
        }

        return $this->showHealth($controller);
    }

    // ── Health report ─────────────────────────────────────────────────────────

    private function showHealth(SystemHealthController $controller): int
    {
        $response = $controller->index();
        $data     = $response->getData(true);

        if (empty($data['data'])) {
            $this->error('No health data returned.');
            return self::FAILURE;
        }

        $payload  = $data['data'];
        $overall  = $payload['overall']  ?? 'unknown';
        $summary  = $payload['summary']  ?? [];
        $groups   = $payload['groups']   ?? [];
        $checkedAt = $payload['checked_at'] ?? now()->toIso8601String();

        // ── Header ────────────────────────────────────────────────────────────
        $overallColor = match ($overall) {
            'pass'    => 'green',
            'warning' => 'yellow',
            default   => 'red',
        };

        $this->newLine();
        $this->line("  <fg=cyan;options=bold>SYSTEM HEALTH REPORT</>");
        $this->line("  Checked at: {$checkedAt}");
        $this->newLine();
        $this->line("  Overall: <fg={$overallColor};options=bold>" . strtoupper($overall) . "</>");
        $this->line("  Pass: {$summary['pass']}  Warning: {$summary['warning']}  Fail: {$summary['fail']}  Critical: {$summary['critical']}  Total: {$summary['total']}");
        $this->newLine();

        // ── Filter by group if requested ──────────────────────────────────────
        $only = $this->option('group');

        foreach ($groups as $groupName => $checks) {
            if ($only && $groupName !== $only) {
                continue;
            }

            $this->line("  <options=bold>" . strtoupper($groupName) . "</>");

            foreach ($checks as $check) {
                $status  = $check['status'] ?? 'unknown';
                $label   = $check['label']  ?? $check['key'] ?? '?';
                $message  = $check['message']  ?? '';
                $fixHint  = $check['fix_hint'] ?? null;
                $color    = $this->statusColors[$status] ?? 'white';

                $this->line(sprintf(
                    "    <fg=%s>[%s]</> %s — %s",
                    $color,
                    strtoupper($status),
                    $label,
                    $message
                ));

                if ($status !== 'pass' && $fixHint) {
                    $this->line("         <fg=gray>Hint: {$fixHint}</>");
                }
            }

            $this->newLine();
        }

        // ── Snapshot ──────────────────────────────────────────────────────────
        if ($this->option('snapshot')) {
            $this->line("  <fg=cyan>[snapshot]</> Result stored in cache.");
            $this->newLine();
        }

        return $overall === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    // ── Errors report ─────────────────────────────────────────────────────────

    private function showErrors(SystemHealthController $controller, Request $request): int
    {
        $response = $controller->errors($request);
        $data     = $response->getData(true);

        if (empty($data['data'])) {
            $this->info('No recent errors found.');
            return self::SUCCESS;
        }

        $errors = $data['data']['errors'] ?? [];
        $total  = $data['data']['total']  ?? count($errors);

        $this->newLine();
        $this->line("  <fg=cyan;options=bold>RECENT ERRORS</> (showing " . count($errors) . " of {$total})");
        $this->newLine();

        foreach ($errors as $err) {
            $source    = $err['source']    ?? 'unknown';
            $severity  = $err['severity']  ?? '';
            $message   = $err['message']   ?? '';
            $occurredAt = $err['occurred_at'] ?? $err['created_at'] ?? '';
            $color     = match ($severity) {
                'critical' => 'red',
                'warning'  => 'yellow',
                default    => 'white',
            };

            $this->line(sprintf(
                "  <fg=%s>[%s]</> <fg=gray>%s</> %s",
                $color,
                strtoupper($severity ?: $source),
                $occurredAt,
                $message
            ));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
