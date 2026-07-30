<?php

namespace App\Services;

use App\Jobs\SendBulkEmailCampaignJob;
use App\Models\BulkEmailCampaign;
use App\Models\MarketingContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the recipient list for a bulk email send from admin-supplied
 * filters, snapshots it onto the campaign, and queues the send job.
 *
 * Unsubscribed contacts are always excluded — this is not a filter option,
 * it is a hard rule enforced here regardless of what the caller passes in.
 */
class BulkEmailService
{
    public function recipientQuery(array $filters): Builder
    {
        $query = MarketingContact::query()->where('status', '!=', 'unsubscribed');

        // A contact can belong to several markets, so this must match ANY of
        // them rather than only the primary `market` column — otherwise a
        // contact added to `germany` alongside `test` would be silently left
        // out of the germany campaign.
        //
        // `markets` (array) targets several markets in one send. Because the
        // filter narrows contact ROWS, a contact belonging to two of the
        // targeted markets is still selected exactly once — no de-duplication
        // step is needed and nobody can be emailed twice by one campaign.
        $markets = [];
        if (! empty($filters['markets']) && is_array($filters['markets'])) {
            $markets = array_values(array_filter($filters['markets']));
        } elseif (! empty($filters['market'])) {
            $markets = [$filters['market']];
        }

        if (! empty($markets)) {
            if (MarketingContact::supportsMultipleMarkets()) {
                $query->whereHas('marketMemberships', fn ($q) => $q->whereIn('market', $markets));
            } else {
                $query->whereIn('market', $markets);
            }
        }

        if (! empty($filters['company'])) {
            $query->where('company', 'like', '%' . $filters['company'] . '%');
        }

        if (! empty($filters['country'])) {
            $query->where('country', $filters['country']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'unsubscribed') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                  ->orWhere('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('company', 'like', $term);
            });
        }

        return $query;
    }

    public function countRecipients(array $filters): int
    {
        return $this->recipientQuery($filters)->count();
    }

    public function createCampaign(string $subject, string $bodyHtml, array $filters, int $createdBy): BulkEmailCampaign
    {
        return DB::transaction(function () use ($subject, $bodyHtml, $filters, $createdBy) {
            $contactIds = $this->recipientQuery($filters)->pluck('email', 'id');

            $campaign = BulkEmailCampaign::create([
                'subject'          => $subject,
                'body_html'        => $bodyHtml,
                'filters'          => $filters,
                'total_recipients' => $contactIds->count(),
                'status'           => 'queued',
                'created_by'       => $createdBy,
            ]);

            $rows = [];
            $now  = now();
            foreach ($contactIds as $contactId => $email) {
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'contact_id'  => $contactId,
                    'email'       => $email,
                    'status'      => 'pending',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('bulk_email_campaign_recipients')->insert($chunk);
            }

            return $campaign;
        });
    }

    public function dispatch(BulkEmailCampaign $campaign): void
    {
        SendBulkEmailCampaignJob::dispatch($campaign->id);
    }
}
