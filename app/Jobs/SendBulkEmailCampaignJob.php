<?php

namespace App\Jobs;

use App\Mail\BulkCampaignEmail;
use App\Models\BulkEmailCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one bulk_email_campaigns row to its pending recipients.
 *
 * Resumable by design: it only ever processes recipients still marked
 * 'pending', so re-running (manual retry, or a queue worker retry after a
 * crash) never double-emails someone already sent to.
 */
class SendBulkEmailCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 3600;

    public function __construct(public int $campaignId) {}

    public function handle(): void
    {
        $campaign = BulkEmailCampaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $campaign->update(['status' => 'sending']);

        $campaign->recipients()
            ->with('contact')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($campaign) {
                foreach ($chunk as $recipient) {
                    $unsubscribeUrl = url("/api/v1/marketing-contacts/unsubscribe/{$recipient->contact->unsubscribe_token}");

                    try {
                        Mail::to($recipient->email)->send(
                            new BulkCampaignEmail($campaign->subject, $campaign->body_html, $unsubscribeUrl)
                        );

                        $recipient->update(['status' => 'sent', 'sent_at' => now()]);
                        $campaign->increment('sent_count');
                    } catch (\Throwable $e) {
                        $recipient->update(['status' => 'failed', 'error' => $e->getMessage()]);
                        $campaign->increment('failed_count');

                        Log::warning('BulkEmailCampaign: recipient send failed', [
                            'campaign_id' => $campaign->id,
                            'email'       => $recipient->email,
                            'error'       => $e->getMessage(),
                        ]);
                    }

                    // Gentle pacing to stay inside SMTP provider rate limits.
                    usleep(150_000);
                }
            });

        $campaign->update([
            'status'       => $campaign->failed_count > 0 && $campaign->sent_count === 0 ? 'failed' : 'completed',
            'completed_at' => now(),
        ]);
    }
}
