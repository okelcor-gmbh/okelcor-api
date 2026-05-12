<?php

namespace App\Console\Commands;

use App\Models\QuoteRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateQuoteAttachmentsToPrivate extends Command
{
    protected $signature   = 'quotes:migrate-attachments';
    protected $description = 'Move quote attachments from public disk to private disk (P2 storage hardening)';

    public function handle(): int
    {
        $quotes = QuoteRequest::whereNotNull('attachment_path')->get();

        if ($quotes->isEmpty()) {
            $this->info('No quote attachments found — nothing to migrate.');
            return 0;
        }

        $moved   = 0;
        $missing = 0;
        $already = 0;

        foreach ($quotes as $quote) {
            $path = $quote->attachment_path;

            $privateExists = Storage::disk('local')->exists($path);
            $publicExists  = Storage::disk('public')->exists($path);

            if ($privateExists) {
                // Already on private disk — remove stale public copy if present
                if ($publicExists) {
                    Storage::disk('public')->delete($path);
                    $this->line("  [cleanup] Removed public copy: {$path}");
                }
                $already++;
                continue;
            }

            if (! $publicExists) {
                $this->warn("  [missing] File not found on either disk: {$path} (quote #{$quote->id})");
                $missing++;
                continue;
            }

            // Read from public, write to private, delete from public
            $content = Storage::disk('public')->get($path);
            Storage::disk('local')->put($path, $content);
            Storage::disk('public')->delete($path);

            $this->line("  [moved] {$path} (quote #{$quote->id})");
            $moved++;
        }

        $this->newLine();
        $this->info("Done. Moved: {$moved} | Already private: {$already} | Missing: {$missing}");

        return 0;
    }
}
