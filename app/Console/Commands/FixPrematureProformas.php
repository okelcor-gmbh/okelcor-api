<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Find orders where a proforma was issued before the customer accepted the order
 * confirmation, then optionally supersede those proformas.
 *
 * Usage:
 *   php artisan orders:fix-premature-proformas --dry-run   (inspect only)
 *   php artisan orders:fix-premature-proformas --apply     (mark as superseded)
 */
class FixPrematureProformas extends Command
{
    protected $signature = 'orders:fix-premature-proformas
                            {--dry-run  : List affected orders without making changes}
                            {--apply    : Supersede the premature proformas}';

    protected $description = 'Find and optionally supersede proformas issued before customer acceptance';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $apply  = $this->option('apply');

        if (! $dryRun && ! $apply) {
            $this->error('Specify --dry-run to inspect or --apply to fix. Aborting.');
            return self::FAILURE;
        }

        // Find orders with pending or rejected acceptance that have an active proforma
        $affectedDocuments = TradeDocument::query()
            ->where('type', 'proforma')
            ->whereIn('status', ['issued', 'sent'])
            ->whereHas('order', fn ($q) => $q->whereIn('customer_acceptance_status', ['pending', 'rejected']))
            ->with('order')
            ->get();

        if ($affectedDocuments->isEmpty()) {
            $this->info('No premature proformas found. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Premature proformas found: ' . $affectedDocuments->count());
        $this->line('');

        $headers = ['Order Ref', 'Acceptance Status', 'Proforma No.', 'Proforma Status', 'Issued At'];
        $rows    = $affectedDocuments->map(fn ($d) => [
            $d->order?->ref ?? '(unknown)',
            $d->order?->customer_acceptance_status ?? '—',
            $d->number,
            $d->status,
            $d->issued_at?->toDateString() ?? '—',
        ])->toArray();

        $this->table($headers, $rows);

        if ($dryRun) {
            $this->line('');
            $this->info('[dry-run] No changes made. Run with --apply to supersede these proformas.');
            return self::SUCCESS;
        }

        // --apply path
        $this->line('');
        $this->warn('Superseding ' . $affectedDocuments->count() . ' proforma(s)...');
        $this->line('');

        $superseded = 0;
        $failed     = 0;
        $reason     = 'Superseded because proforma was generated before customer acceptance.';

        foreach ($affectedDocuments as $doc) {
            $order = $doc->order;

            try {
                $doc->update([
                    'status'           => 'superseded',
                    'superseded_at'    => now(),
                    'superseded_by_id' => null,
                    'supersede_reason' => $reason,
                ]);

                try {
                    OrderLog::create([
                        'order_id'         => $order?->id,
                        'order_ref'        => $order?->ref ?? $doc->order_ref,
                        'admin_user_id'    => null,
                        'admin_user_email' => null,
                        'action'           => 'premature_proforma_superseded',
                        'old_value'        => $doc->number,
                        'new_value'        => 'superseded',
                        'notes'            => $reason,
                        'ip_address'       => '127.0.0.1',
                        'created_at'       => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('OrderLog write failed (FixPrematureProformas)', [
                        'order_ref' => $order?->ref,
                        'error'     => $e->getMessage(),
                    ]);
                }

                $this->info("  ✓ Superseded {$doc->number}  [{$order?->ref}]");
                $superseded++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Failed for {$doc->number}  [{$order?->ref}]: {$e->getMessage()}");
                Log::error('FixPrematureProformas: supersede failed', [
                    'document_id' => $doc->id,
                    'order_ref'   => $order?->ref,
                    'error'       => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->line('');
        $this->info("Done. Superseded: {$superseded}  |  Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
