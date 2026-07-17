<?php

namespace App\Console\Commands;

use App\Services\AdminInsightsService;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes (see routes/console.php). Silently does
 * nothing if GEMINI_API_KEY isn't set, or if the Gemini call fails for any
 * reason — see AdminInsightsService for the full degrade-gracefully design.
 */
class GenerateAdminInsights extends Command
{
    protected $signature = 'insights:generate';

    protected $description = 'Generate AI-summarized admin dashboard insights (revenue/orders/inventory/security/quotes)';

    public function handle(AdminInsightsService $service): int
    {
        $service->generate();

        return self::SUCCESS;
    }
}
