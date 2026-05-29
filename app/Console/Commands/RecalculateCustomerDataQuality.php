<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerDataQualityService;
use Illuminate\Console\Command;

class RecalculateCustomerDataQuality extends Command
{
    protected $signature = 'customers:recalculate-data-quality
                            {--all : Process every customer}
                            {--id= : Process a single customer by ID}
                            {--unscored : Only process customers with no score yet}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Compute/refresh data quality scores and duplicate flags for customers';

    public function handle(CustomerDataQualityService $service): int
    {
        $dryRun = $this->option('dry-run');
        $singleId = $this->option('id');

        if ($singleId) {
            return $this->processSingle((int) $singleId, $service, $dryRun);
        }

        if (! $this->option('all') && ! $this->option('unscored')) {
            $this->error('Pass --all, --unscored, or --id=N');
            return self::FAILURE;
        }

        return $this->processBulk($service, $dryRun);
    }

    private function processSingle(int $id, CustomerDataQualityService $service, bool $dryRun): int
    {
        $customer = Customer::find($id);

        if (! $customer) {
            $this->error("Customer #{$id} not found.");
            return self::FAILURE;
        }

        $this->showPreview($customer, $service);

        if (! $dryRun) {
            $updated = $service->computeAndPersist($customer);
            $this->info("Updated customer #{$id}: score={$updated->data_quality_score}, status={$updated->data_review_status}");
        }

        return self::SUCCESS;
    }

    private function processBulk(CustomerDataQualityService $service, bool $dryRun): int
    {
        $query = Customer::query();

        if ($this->option('unscored')) {
            $query->whereNull('data_quality_score');
        }

        $total     = $query->count();
        $processed = 0;
        $errors    = 0;

        $this->info($dryRun ? "[dry-run] Would process {$total} customers." : "Processing {$total} customers...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($customers) use ($service, $dryRun, &$processed, &$errors, $bar) {
            foreach ($customers as $customer) {
                try {
                    if (! $dryRun) {
                        $service->computeAndPersist($customer);
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->newLine();
                    $this->warn("  Customer #{$customer->id} failed: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Processed: {$processed}, Errors: {$errors}");

        return self::SUCCESS;
    }

    private function showPreview(Customer $customer, CustomerDataQualityService $service): void
    {
        $normalized = $service->buildNormalized($customer);
        $duplicates = $service->findDuplicates($customer);

        $this->line("Customer #{$customer->id}: {$customer->email} / " . ($customer->company_name ?? 'no company'));
        $this->line("  normalized_email: {$normalized['normalized_email']}");
        $this->line("  normalized_company: " . ($normalized['normalized_company_name'] ?? 'n/a'));

        if ($duplicates) {
            $this->warn("  Potential duplicates found:");
            foreach ($duplicates as $type => $dup) {
                $this->warn("    [{$type}] Customer #{$dup->id}: {$dup->email} / {$dup->company_name}");
            }
        }
    }
}
