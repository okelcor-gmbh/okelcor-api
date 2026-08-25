<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Move everything one admin account owns — contribution ledger, assigned
 * work, notifications, created records, sign-offs, uploads — onto another
 * account. Built for the real case: the same human getting a work email,
 * whose history must follow them.
 *
 * The referencing columns are discovered from the database's own foreign
 * keys at runtime, so a table added next month is covered without anyone
 * remembering this command exists.
 *
 * Deliberately NOT moved:
 *  - admin_security_events / admin_login_histories — the audit trail
 *    records what the old account actually did; rewriting it would make
 *    the security history lie.
 *  - admin_push_tokens — device registrations belong to the login, and
 *    the new account registers its own devices.
 */
class MergeAdminAccount extends Command
{
    protected $signature   = 'admin:merge-account {from : email of the old account} {to : email of the new account} {--dry-run : report what would move, change nothing}';
    protected $description = 'Re-point every record owned by one admin account to another (audit trail excluded)';

    private const SKIP_TABLES = [
        'admin_security_events',
        'admin_login_histories',
        'admin_push_tokens',
    ];

    public function handle(): int
    {
        $from = AdminUser::where('email', $this->argument('from'))->first();
        $to   = AdminUser::where('email', $this->argument('to'))->first();

        if (! $from || ! $to) {
            $this->error('Both accounts must exist. Not found: ' . implode(', ', array_filter([
                $from ? null : $this->argument('from'),
                $to ? null : $this->argument('to'),
            ])));

            return self::FAILURE;
        }

        if ($from->id === $to->id) {
            $this->error('From and to are the same account.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved  = [];
        $failed = [];

        foreach ($this->referencingColumns() as [$table, $column]) {
            if (in_array($table, self::SKIP_TABLES, true)) {
                continue;
            }

            try {
                $count = DB::table($table)->where($column, $from->id)->count();
                if ($count === 0) {
                    continue;
                }

                if (! $dryRun) {
                    DB::table($table)->where($column, $from->id)->update([$column => $to->id]);
                }

                $moved[] = [$table, $column, $count];
            } catch (\Throwable $e) {
                // A unique-key collision (both accounts in the same thread,
                // say) fails that one table, not the merge. Idempotent:
                // re-running moves whatever is left.
                $failed[] = [$table, $column, $e->getMessage()];
            }
        }

        $verb = $dryRun ? 'Would move' : 'Moved';
        $this->info("{$verb} from {$from->email} (#{$from->id}) to {$to->email} (#{$to->id}):");
        if ($moved === []) {
            $this->line('  nothing — no records reference the old account outside the audit trail.');
        }
        foreach ($moved as [$table, $column, $count]) {
            $this->line("  {$table}.{$column}: {$count} row(s)");
        }
        foreach ($failed as [$table, $column, $error]) {
            $this->warn("  FAILED {$table}.{$column}: {$error}");
        }
        $this->line('Skipped by design: ' . implode(', ', self::SKIP_TABLES));

        if (! $dryRun) {
            Log::info('Admin account merged', [
                'from'  => $from->email,
                'to'    => $to->email,
                'moved' => collect($moved)->map(fn ($m) => "{$m[0]}.{$m[1]}={$m[2]}")->all(),
            ]);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every (table, column) holding a foreign key into admin_users.id,
     * straight from the database's own metadata.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function referencingColumns(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return collect(DB::select(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME = ?
                   AND REFERENCED_COLUMN_NAME = ?',
                ['admin_users', 'id']
            ))->map(fn ($r) => [$r->t, $r->c])->all();
        }

        // sqlite (tests)
        $columns = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            foreach (DB::select("PRAGMA foreign_key_list('{$table->name}')") as $fk) {
                if ($fk->table === 'admin_users' && (($fk->to ?? 'id') === 'id' || $fk->to === null)) {
                    $columns[] = [$table->name, $fk->from];
                }
            }
        }

        return $columns;
    }
}
