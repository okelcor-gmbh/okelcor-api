<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminInsight;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/insights
 *
 * Serves whatever AdminInsightsService's scheduled job last produced (see
 * routes/console.php — every 15 minutes). Never calls the AI API directly
 * from a request — always instant, always cached-by-table. `data: []` with
 * a null `generated_at` means the job hasn't produced anything yet (e.g.
 * GEMINI_API_KEY isn't set) — not an error, just nothing to show.
 */
class AdminInsightController extends Controller
{
    public function index(): JsonResponse
    {
        $latest = AdminInsight::orderByDesc('generated_at')->first();

        if (! $latest) {
            return response()->json([
                'data'            => [],
                'generated_at'    => null,
                'next_refresh_at' => null,
                'message'         => 'success',
            ]);
        }

        $insights = AdminInsight::where('generated_at', $latest->generated_at)
            ->orderBy('rank')
            ->get();

        return response()->json([
            'data' => $insights->map(fn ($i) => [
                'id'         => $i->external_id,
                'category'   => $i->category,
                'severity'   => $i->severity,
                'headline'   => $i->headline,
                'detail'     => $i->detail,
                'action_url' => $i->action_url,
            ])->values(),
            'generated_at'    => $latest->generated_at->toIso8601String(),
            'next_refresh_at' => $latest->generated_at->copy()->addMinutes(15)->toIso8601String(),
            'message'         => 'success',
        ]);
    }
}
