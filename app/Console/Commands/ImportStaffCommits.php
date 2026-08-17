<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\StaffActivity;
use App\Services\StaffActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Reads git history into the contribution ledger.
 *
 * The ledger's seven original sources are all business operations — orders,
 * documents, sign-offs, customer replies, campaigns, invoices, partner audits.
 * Somebody who builds the system rather than operating it touches almost none
 * of those tables, so their month came back empty. That was never true about
 * their work; it was true about what the ledger knew how to look at.
 *
 * Development has a system of record, it simply is not this database. This
 * command reads it on exactly the same terms as every other source: attributed
 * to a named person, idempotent, and never inventing anything.
 *
 * Two ways in, because the two repositories do not live in the same place:
 *
 *   --repo=/path/to/repo     read git directly (the API repo is on the server)
 *   --file=commits.tsv       read an exported log (the frontend is on Vercel;
 *                            generate the file locally, upload, import)
 *
 * Matching is by author e-mail against the admin account's e-mail. A git
 * identity that differs from the login e-mail is normal, so `staff.git_aliases`
 * maps one to the other rather than requiring anyone to rewrite history.
 */
class ImportStaffCommits extends Command
{
    protected $signature = 'staff:import-commits
        {--repo=* : Repository path to read. Repeatable. Defaults to config, then this project}
        {--file= : Import from an exported log instead of running git}
        {--repo-name= : Label for --file imports}
        {--since=6 months ago : How far back to read}
        {--fix : Write the rows. Without this the command only reports what it would do}';

    protected $description = 'Read git commit history into the staff contribution ledger, attributed by author e-mail';

    /**
     * Field separator. A unit separator rather than a pipe or a tab, because a
     * commit subject can and does contain both.
     */
    private const SEP = "\x1f";

    private const FORMAT = '%H' . self::SEP . '%aE' . self::SEP . '%aN' . self::SEP . '%aI' . self::SEP . '%s';

    public function handle(StaffActivityRecorder $recorder): int
    {
        if (! Schema::hasTable('staff_activities')) {
            $this->error('The staff_activities table does not exist. Run `artisan migrate` first.');

            return self::FAILURE;
        }

        StaffActivity::forgetLedgerCheck();

        $write = (bool) $this->option('fix');

        $this->info($write
            ? 'Reading git history into the ledger.'
            : 'Survey only — nothing will be written. Add --fix once the numbers below look right.');
        $this->newLine();

        $commits = $this->option('file')
            ? $this->fromFile()
            : $this->fromRepositories();

        if ($commits === null) {
            return self::FAILURE;
        }

        if ($commits === []) {
            $this->warn('No commits found in that range.');

            return self::SUCCESS;
        }

        $accounts   = $this->accountsByEmail();
        $written    = 0;
        $tally      = [];
        $unmatched  = [];

        foreach ($commits as $commit) {
            $adminId = $accounts[mb_strtolower($commit['email'])] ?? null;

            if ($adminId === null) {
                // Counted by identity rather than by commit: "412 commits
                // skipped" tells you nothing, "412 from noreply@github.com"
                // tells you exactly what to add to the alias map.
                $key = $commit['email'] . ' (' . $commit['name'] . ')';
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                continue;
            }

            if ($write) {
                $activity = $recorder->fromGitCommit($commit, $adminId);
                if ($activity === null) {
                    continue;
                }
                $name = $activity->admin_name;
            } else {
                $name = AdminUser::find($adminId)?->name ?? '(unknown)';
            }

            $written++;
            $tally[$name][$commit['repo']] = ($tally[$name][$commit['repo']] ?? 0) + 1;
        }

        $this->renderTally($tally, $written);

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('Commits by an author with no admin account — add these to staff.git_aliases if they are one of yours:');
            arsort($unmatched);
            foreach ($unmatched as $who => $count) {
                $this->line(sprintf('  %-52s %d', $who, $count));
            }
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --fix to import.');
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fromRepositories(): ?array
    {
        $repos = (array) $this->option('repo');
        $repos = array_values(array_filter($repos));

        if ($repos === []) {
            $configured = (array) config('staff.repositories', []);
            $repos = $configured !== [] ? $configured : [base_path()];
        }

        $commits = [];

        foreach ($repos as $name => $path) {
            $label = is_string($name) ? $name : basename((string) $path);

            if (! is_dir($path . '/.git')) {
                $this->error("Not a git repository: {$path}");

                return null;
            }

            $found = $this->readGit((string) $path, $label);

            if ($found === null) {
                return null;
            }

            $this->line(sprintf('  %-22s %6d commits', $label, count($found)));
            $commits = array_merge($commits, $found);
        }

        return $commits;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readGit(string $path, string $label): ?array
    {
        $process = new Process([
            'git', 'log',
            '--no-merges',                      // a merge is not a second piece of work
            '--since=' . $this->option('since'),
            '--pretty=format:' . self::FORMAT,
        ], $path);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->error("git log failed in {$path}: " . trim($e->getProcess()->getErrorOutput()));

            return null;
        }

        return $this->parse($process->getOutput(), $label);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fromFile(): ?array
    {
        $file = (string) $this->option('file');

        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return null;
        }

        $label = (string) ($this->option('repo-name') ?: basename($file, '.tsv'));

        $this->line('  Reading ' . $file);
        $this->newLine();
        $this->line('  Generate one with:');
        $this->line('    git log --no-merges --since="6 months ago" \\');
        $this->line('      --pretty=format:\'%H%x1f%aE%x1f%aN%x1f%aI%x1f%s\' > commits.tsv');
        $this->newLine();

        return $this->parse((string) file_get_contents($file), $label);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $output, string $repo): array
    {
        $commits = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = explode(self::SEP, $line);

            if (count($parts) < 5) {
                continue;
            }

            [$sha, $email, $name, $date, $subject] = $parts;

            $commits[] = [
                'sha'     => trim($sha),
                'email'   => trim($email),
                'name'    => trim($name),
                'date'    => trim($date),
                'subject' => trim($subject),
                'repo'    => $repo,
            ];
        }

        return $commits;
    }

    /**
     * Login e-mail → admin id, plus the configured git aliases.
     *
     * A git identity that differs from the login e-mail is the normal case, not
     * an edge case — people commit from a personal address for years before
     * they have a work account. Mapping it beats asking anyone to rewrite
     * history.
     *
     * @return array<string, int>
     */
    private function accountsByEmail(): array
    {
        $accounts = [];

        foreach (AdminUser::query()->get(['id', 'email']) as $admin) {
            $accounts[mb_strtolower($admin->email)] = $admin->id;
        }

        foreach ((array) config('staff.git_aliases', []) as $gitEmail => $loginEmail) {
            $id = $accounts[mb_strtolower((string) $loginEmail)] ?? null;

            if ($id !== null) {
                $accounts[mb_strtolower((string) $gitEmail)] = $id;
            }
        }

        return $accounts;
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     */
    private function renderTally(array $tally, int $written): void
    {
        $this->newLine();

        if ($tally === []) {
            $this->warn('No commit matched an admin account. Check staff.git_aliases.');

            return;
        }

        ksort($tally);

        $repos = [];
        foreach ($tally as $counts) {
            foreach (array_keys($counts) as $repo) {
                $repos[$repo] = true;
            }
        }
        $repos = array_keys($repos);
        sort($repos);

        $rows = [];
        foreach ($tally as $name => $counts) {
            $row = [$name];
            foreach ($repos as $repo) {
                $row[] = $counts[$repo] ?? '·';
            }
            $row[] = array_sum($counts);
            $rows[] = $row;
        }

        $this->table(array_merge(['Person'], $repos, ['Commits']), $rows);
        $this->info("{$written} commits attributed.");
    }
}
