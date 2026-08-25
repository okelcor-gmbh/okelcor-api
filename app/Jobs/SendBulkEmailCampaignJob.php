<?php

namespace App\Jobs;

use App\Mail\BulkCampaignEmail;
use App\Models\BulkEmailCampaign;
use App\Services\CampaignMergeTags;
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

        // When the sync driver runs this after the response (see
        // BulkEmailService::dispatch), the send still lives inside a web PHP
        // process whose execution limit was sized for requests, not for
        // hundreds of SMTP round-trips. Lift it — $timeout above stays the
        // guard on real queue workers.
        @set_time_limit(0);

        $campaign->update(['status' => 'sending']);

        $campaign->recipients()
            ->with('contact')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($campaign) {
                $mergeTags = app(CampaignMergeTags::class);

                foreach ($chunk as $recipient) {
                    // A contact deleted between snapshot and send must cost one
                    // recipient, not the whole campaign: unguarded, the null
                    // deref below aborts the job mid-list and every remaining
                    // recipient is silently never sent to.
                    if (! $recipient->contact) {
                        $recipient->update(['status' => 'failed', 'error' => 'Marketing contact no longer exists']);
                        $campaign->increment('failed_count');

                        continue;
                    }

                    $unsubscribeUrl = url("/api/v1/marketing-contacts/unsubscribe/{$recipient->contact->unsubscribe_token}");

                    // Substitutes every merge tag for this one recipient:
                    // [[FIRST_NAME]], [[COMPANY]] and friends, plus
                    // [[UNSUBSCRIBE_URL]] — which is how a fully-designed
                    // campaign (its own <html>/<body> and its own styled
                    // unsubscribe link) reaches the real per-recipient URL
                    // instead of only ever getting the generic footer
                    // emails.bulk-campaign appends for a plain HTML snippet
                    // (see that view for the other half of this — it skips its
                    // own wrapper entirely when body_html is already a full
                    // document, since nesting two <html> documents is invalid).
                    $personalizedBody = $mergeTags->apply($campaign->body_html, $recipient->contact, $unsubscribeUrl);
                    $personalizedText = $campaign->body_text === null
                        ? null
                        : $mergeTags->apply($campaign->body_text, $recipient->contact, $unsubscribeUrl);

                    $personalizedSubject = $mergeTags->apply($campaign->subject, $recipient->contact, $unsubscribeUrl);

                    // Engagement tracking: the open pixel + signed click
                    // redirects, per recipient. This is what feeds the
                    // marketer scoreboard's open and completion rates.
                    $personalizedBody = app(\App\Services\CampaignTrackingService::class)
                        ->instrument($personalizedBody, $recipient, $unsubscribeUrl);

                    try {
                        Mail::to($recipient->email)->send(
                            new BulkCampaignEmail($personalizedSubject, $personalizedBody, $unsubscribeUrl, $personalizedText)
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
