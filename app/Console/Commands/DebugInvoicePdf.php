<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DebugInvoicePdf extends Command
{
    protected $signature = 'invoices:debug-pdf
                            {invoice_id : Invoice ID to inspect}
                            {--regenerate : Regenerate PDF if file is missing on disk}';

    protected $description = 'Diagnostic: inspect PDF path resolution for an invoice and optionally regenerate the file';

    public function handle(): int
    {
        $id      = (int) $this->argument('invoice_id');
        $invoice = Invoice::find($id);

        if (! $invoice) {
            $this->error("Invoice #{$id} not found in database.");
            return self::FAILURE;
        }

        // ------------------------------------------------------------------
        // 1. Invoice record
        // ------------------------------------------------------------------
        $this->line('');
        $this->info('=== Invoice record ===');
        $this->table(['Field', 'Value'], [
            ['id',           $invoice->id],
            ['invoice_number', $invoice->invoice_number],
            ['order_ref',    $invoice->order_ref ?? 'NULL'],
            ['customer_id',  $invoice->customer_id],
            ['amount',       '€' . number_format((float) $invoice->amount, 2)],
            ['status',       $invoice->status],
            ['released_at',  $invoice->released_at?->toIso8601String() ?? 'NULL'],
            ['pdf_url (raw)', $invoice->pdf_url ?? 'NULL'],
        ]);

        if (! $invoice->pdf_url) {
            $this->warn('pdf_url is NULL — no PDF has been generated yet. Run with --regenerate to create it.');
        }

        // ------------------------------------------------------------------
        // 2. Path normalization (mirrors InvoiceDownloadController logic)
        // ------------------------------------------------------------------
        $raw = $invoice->pdf_url ?? '';

        if ($raw !== '') {
            if (preg_match('#/storage/(.+)$#', $raw, $m)) {
                $normalized = $m[1];
                $normalizeSource = 'stripped /storage/ prefix';
            } else {
                $normalized = ltrim($raw, '/');
                $normalizeSource = 'stripped leading slash';
            }
        } else {
            $normalized = '';
            $normalizeSource = 'N/A (pdf_url is empty)';
        }

        $fallback     = 'invoices/' . $invoice->invoice_number . '.pdf';
        $fullPath     = $normalized !== '' ? Storage::disk('public')->path($normalized) : 'N/A';
        $fullFallback = Storage::disk('public')->path($fallback);

        $diskExists     = $normalized !== '' && Storage::disk('public')->exists($normalized);
        $fileExists     = $normalized !== '' && file_exists($fullPath);
        $fallbackExists = Storage::disk('public')->exists($fallback);

        $this->line('');
        $this->info('=== Path resolution ===');
        $this->table(['Field', 'Value'], [
            ['pdf_url raw',             $raw ?: 'NULL'],
            ['normalize method',        $normalizeSource],
            ['normalized disk path',    $normalized ?: 'N/A'],
            ['full absolute path',      $fullPath],
            ['Storage disk exists',     $diskExists ? 'YES ✓' : 'NO ✗'],
            ['file_exists()',           $fileExists ? 'YES ✓' : 'NO ✗'],
            ['filesize',                ($fileExists ? filesize($fullPath) . ' bytes' : 'N/A')],
            ['--- fallback path ---',   ''],
            ['fallback disk path',      $fallback],
            ['fallback full path',      $fullFallback],
            ['fallback disk exists',    $fallbackExists ? 'YES ✓' : 'NO ✗'],
        ]);

        // ------------------------------------------------------------------
        // 3. Environment & disk config
        // ------------------------------------------------------------------
        $this->line('');
        $this->info('=== Environment & filesystem ===');
        $symlinkPath   = public_path('storage');
        $isLink        = is_link($symlinkPath);
        $linkTarget    = $isLink ? readlink($symlinkPath) : '(not a symlink)';
        $symlinkExists = file_exists($symlinkPath);

        $this->table(['Field', 'Value'], [
            ['APP_URL',                   config('app.url')],
            ['filesystems.default',       config('filesystems.default')],
            ['public disk root',          config('filesystems.disks.public.root')],
            ['public disk url',           config('filesystems.disks.public.url')],
            ['storage_path("app/public")', storage_path('app/public')],
            ['public/storage exists',     $symlinkExists ? 'YES ✓' : 'NO ✗'],
            ['public/storage is_link()',  $isLink ? 'YES ✓' : 'NO'],
            ['readlink target',           $linkTarget],
        ]);

        // ------------------------------------------------------------------
        // 4. Files in invoices/ directory
        // ------------------------------------------------------------------
        $invoiceDir = Storage::disk('public')->path('invoices');
        $this->line('');
        $this->info("=== Directory listing: {$invoiceDir} ===");

        if (is_dir($invoiceDir)) {
            $files    = scandir($invoiceDir) ?: [];
            $pdfFiles = array_values(array_filter($files, fn ($f) => str_ends_with($f, '.pdf')));

            if (empty($pdfFiles)) {
                $this->warn('No .pdf files found in invoices/ directory.');
            } else {
                $this->table(
                    ['Filename', 'Size', 'Modified'],
                    array_map(fn ($f) => [
                        $f,
                        number_format(filesize($invoiceDir . '/' . $f)) . ' bytes',
                        date('Y-m-d H:i:s', filemtime($invoiceDir . '/' . $f)),
                    ], $pdfFiles)
                );
            }
        } else {
            $this->warn("invoices/ directory does NOT exist at: {$invoiceDir}");
        }

        // ------------------------------------------------------------------
        // 5. Optional regeneration
        // ------------------------------------------------------------------
        if ($this->option('regenerate') && ! $diskExists && ! $fallbackExists) {
            $this->line('');
            $this->info('=== Regenerating PDF ===');

            if (! $invoice->order_ref) {
                $this->error('Cannot regenerate: invoice has no order_ref.');
                return self::FAILURE;
            }

            $order = Order::with('items')->where('ref', $invoice->order_ref)->first();

            if (! $order) {
                $this->error("Cannot regenerate: order '{$invoice->order_ref}' not found.");
                return self::FAILURE;
            }

            try {
                $pdf  = Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'order' => $order]);
                $path = 'invoices/' . $invoice->invoice_number . '.pdf';

                Storage::disk('public')->put($path, $pdf->output());
                $invoice->update(['pdf_url' => $path]);

                $this->info("PDF regenerated and saved to: {$path}");
                $this->info('pdf_url column updated to: ' . $path);
            } catch (\Throwable $e) {
                $this->error('Regeneration failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        } elseif ($this->option('regenerate')) {
            $this->line('');
            $this->warn('--regenerate flag set but file already exists — skipping regeneration.');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
