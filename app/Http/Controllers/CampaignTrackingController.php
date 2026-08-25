<?php

namespace App\Http\Controllers;

use App\Models\BulkEmailCampaignRecipient;
use App\Services\CampaignTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public half of campaign engagement tracking. Both endpoints are
 * keyed by the recipient's unguessable tracking token and fail SILENTLY
 * toward the reader: a broken pixel still renders as nothing, and a bad
 * click signature lands on the homepage rather than an error page —
 * tracking must never cost a reader their destination.
 */
class CampaignTrackingController extends Controller
{
    /** A 1×1 transparent GIF, decoded once. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    // ── GET /api/v1/campaign/open/{token}.gif ────────────────────────────────
    public function open(string $token): Response
    {
        try {
            $recipient = BulkEmailCampaignRecipient::where('tracking_token', $token)->first();
            if ($recipient) {
                $recipient->forceFill([
                    'opened_at'  => $recipient->opened_at ?? now(),
                    'open_count' => $recipient->open_count + 1,
                ])->save();
            }
        } catch (\Throwable) {
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    // ── GET /api/v1/campaign/click/{token}?u=...&s=... ───────────────────────
    public function click(Request $request, string $token, CampaignTrackingService $tracker): RedirectResponse
    {
        $target    = (string) $request->query('u', '');
        $signature = (string) $request->query('s', '');
        $home      = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        // Only URLs WE signed into an email redirect — anything else goes
        // home. This is what keeps the tracker from being an open redirect.
        if ($target === '' || ! preg_match('#^https?://#i', $target) || ! $tracker->verify($token, $target, $signature)) {
            return redirect()->away($home);
        }

        try {
            $recipient = BulkEmailCampaignRecipient::where('tracking_token', $token)->first();
            if ($recipient) {
                $recipient->forceFill([
                    // A click proves an open even when images were blocked.
                    'opened_at'   => $recipient->opened_at ?? now(),
                    'open_count'  => max(1, $recipient->open_count),
                    'clicked_at'  => $recipient->clicked_at ?? now(),
                    'click_count' => $recipient->click_count + 1,
                ])->save();
            }
        } catch (\Throwable) {
        }

        return redirect()->away($target);
    }
}
