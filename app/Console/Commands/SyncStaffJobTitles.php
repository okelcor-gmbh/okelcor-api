<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the job titles in config/staff.php to the accounts they name.
 *
 * The role column is a permission set, not a job description, and reading it as
 * one mislabels most of this team: two order managers hold `admin` because they
 * also need customers, campaigns and quote requests, and so does the person
 * running operations. Grouping a report by role would file all three under
 * "Admin".
 *
 * Matched on e-mail, because that is the one identifier that belongs to the
 * person rather than to their access. Someone's permissions change when their
 * responsibilities do; their login does not.
 *
 * Will not overwrite a title somebody set by hand in the admin panel unless
 * `--force` is passed. The config is a seed for a fresh server, not the
 * authority — the panel is.
 */
class SyncStaffJobTitles extends Command
{
    protected $signature = 'staff:sync-job-titles
        {--force : Overwrite titles that were already set by hand}
        {--set= : Set one person directly, as email=Job Title}';

    protected $description = 'Apply the configured job titles to admin accounts, matched by e-mail';

    public function handle(): int
    {
        if (! Schema::hasColumn('admin_users', 'job_title')) {
            $this->error('admin_users has no job_title column. Run `artisan migrate` first.');

            return self::FAILURE;
        }

        if ($pair = $this->option('set')) {
            return $this->setOne($pair);
        }

        /** @var array<string, string> $configured */
        $configured = config('staff.job_titles', []);

        if ($configured === []) {
            $this->warn('No job titles are configured. Add them to config/staff.php, or use --set=email="Job Title".');

            return self::SUCCESS;
        }

        $force   = (bool) $this->option('force');
        $applied = 0;
        $kept    = 0;
        $missing = [];

        foreach ($configured as $email => $title) {
            $admin = AdminUser::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])->first();

            if ($admin === null) {
                $missing[] = $email;
                continue;
            }

            if ($admin->hasJobTitle() && ! $force) {
                $this->line(sprintf('  <fg=gray>%-28s kept "%s"</>', $admin->email, $admin->jobTitle()));
                $kept++;
                continue;
            }

            $admin->update(['job_title' => $title]);
            $this->line(sprintf('  %-28s → %s', $admin->email, $title));
            $applied++;
        }

        $this->newLine();
        $this->info("{$applied} set, {$kept} left as they were.");

        if ($missing !== []) {
            // Named rather than counted: a typo in the config and a colleague
            // who has not been given an account look identical in a number.
            $this->warn('No account for: ' . implode(', ', $missing));
        }

        $this->reportUntitled();

        return self::SUCCESS;
    }

    private function setOne(string $pair): int
    {
        if (! str_contains($pair, '=')) {
            $this->error('--set expects email=Job Title.');

            return self::FAILURE;
        }

        [$email, $title] = array_map('trim', explode('=', $pair, 2));

        $admin = AdminUser::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($admin === null) {
            $this->error("No admin account with the e-mail {$email}.");

            return self::FAILURE;
        }

        $admin->update(['job_title' => $title === '' ? null : $title]);

        $this->info("{$admin->email} is now recorded as: " . $admin->jobTitle());

        return self::SUCCESS;
    }

    /**
     * Anyone still falling back to their role.
     *
     * Worth printing every run: a person with no title is not broken, but they
     * will appear in the team report under a permission set rather than a job,
     * and that is the exact confusion this command exists to remove.
     */
    private function reportUntitled(): void
    {
        $untitled = AdminUser::query()
            ->where(fn ($q) => $q->whereNull('job_title')->orWhere('job_title', ''))
            ->orderBy('name')
            ->get(['name', 'email', 'role']);

        if ($untitled->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Still showing a role instead of a job — set these when you know them:');

        $this->table(
            ['Name', 'E-mail', 'Falls back to'],
            $untitled->map(fn ($a) => [
                $a->name,
                $a->email,
                ucwords(str_replace('_', ' ', (string) $a->role)),
            ])->all()
        );
    }
}
