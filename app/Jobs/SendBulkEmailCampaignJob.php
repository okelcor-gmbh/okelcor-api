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

                    // Lets a full, self-contained campaign HTML document
                    // (its own <html>/<body>, its own styled unsubscribe
                    // link) reference the real per-recipient URL via this
                    // literal token, instead of only ever getting the
                    // generic footer emails.bulk-campaign appends for a
                    // plain HTML snippet (see that view for the other half
                    // of this — it skips its own wrapper/footer entirely
                    // when body_html is already a full document, since
                    // nesting two <html> documents is invalid).
                    $personalizedBody = str_replace('[[UNSUBSCRIBE_URL]]', $unsubscribeUrl, $campaign->body_html);

                    try {
                        Mail::to($recipient->email)->send(
                            new BulkCampaignEmail($campaign->subject, $personalizedBody, $unsubscribeUrl)
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
