<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaLibraryService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * One-off: uploads the source images checked into
 * resources/campaign-assets/{$this->argument('set')}/ into the real Media
 * Library, through the exact same MediaLibraryService the admin upload
 * endpoint uses — so the resulting Media rows/URLs are indistinguishable
 * from anything uploaded by hand. Safe to re-run: skips any file whose
 * original name already exists in the target collection.
 *
 * Usage: php artisan campaign:seed-assets croatia-market
 */
class SeedCampaignAssets extends Command
{
    protected $signature = 'campaign:seed-assets {set : Subfolder name under resources/campaign-assets/}';

    protected $description = 'Upload a folder of campaign source images into the Media Library';

    public function handle(MediaLibraryService $mediaLibrary): int
    {
        $set = $this->argument('set');
        $dir = resource_path("campaign-assets/{$set}");

        if (! is_dir($dir)) {
            $this->error("No such folder: {$dir}");
            return self::FAILURE;
        }

        $collection = 'campaigns/' . $set;
        $files      = glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE);

        if (! $files) {
            $this->warn("No images found in {$dir}");
            return self::SUCCESS;
        }

        foreach ($files as $path) {
            $originalName = basename($path);

            if (Media::where('collection', $collection)->where('original_name', $originalName)->exists()) {
                $this->line("Skipped (already uploaded): {$originalName}");
                continue;
            }

            $mime = mime_content_type($path) ?: 'image/jpeg';
            $file = new UploadedFile($path, $originalName, $mime, null, true);

            $media = $mediaLibrary->store(
                file: $file,
                collection: $collection,
                altText: pathinfo($originalName, PATHINFO_FILENAME),
            );

            $this->info("Uploaded: {$originalName} -> {$media->url}");
        }

        return self::SUCCESS;
    }
}
