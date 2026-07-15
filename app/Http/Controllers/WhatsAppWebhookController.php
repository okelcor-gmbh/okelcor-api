<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use App\Services\InquiryQualityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Meta's WhatsApp Business Cloud API webhook — inbound customer
 * messages and outbound delivery/read status updates. Outbound SENDING goes
 * through WhatsAppService instead; this controller only ever receives.
 *
 *   GET  /api/v1/webhooks/whatsapp   — one-time verification handshake
 *   POST /api/v1/webhooks/whatsapp   — message + status events
 *
 * No admin/customer auth on these routes (Meta calls them directly) — the
 * POST route is instead protected by verifying Meta's own HMAC-SHA256
 * request signature (X-Hub-Signature-256, keyed with the App Secret), the
 * same security boundary this app already applies to the Stripe webhook via
 * Stripe-Signature. A request that fails signature verification is rejected
 * before any of its payload is trusted.
 *
 * Reuses the exact same CRM-2 quality-scoring + CRM-3B notification
 * conventions as QuoteRequestController's website/landing-page lead intake
 * (see dispatchInquirySideEffects() there) — duplicated here rather than
 * extracted into a shared service, since that method is private on a
 * different, live-critical controller this session couldn't test end to
 * end; safer to keep the working path untouched.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private InquiryQualityService $qualityService) {}

    // ── GET /webhooks/whatsapp — verification handshake ───────────────────

    public function verify(Request $request): Response
    {
        // Meta sends hub.mode / hub.verify_token / hub.challenge as query
        // params; PHP turns the dots into underscores in $_GET.
        $mode      = $request->query('hub_mode');
        $token     = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        $expected = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $expected && hash_equals((string) $expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', ['mode' => $mode]);

        return response('Forbidden', 403);
    }

    // ── POST /webhooks/whatsapp — messages + status updates ───────────────

    public function handle(Request $request): Response
    {
        if (! $this->verifySignature($request)) {
            Log::warning('WhatsApp webhook rejected: invalid or missing signature');
            return response()->json(['message' => 'invalid signature'], 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleInboundMessage($message, $value['contacts'][0] ?? null);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }

        // Meta expects a fast 200 regardless of internal outcome — failures
        // are logged, not surfaced, so a processing error doesn't turn into
        // a Meta retry-storm against this endpoint.
        return response()->json(['status' => 'ok']);
    }

    // -------------------------------------------------------------------------

    private function handleInboundMessage(array $message, ?array $contact): void
    {
        try {
            $phone = $message['from'] ?? null;
            if (! $phone) {
                return;
            }

            $waMessageId = $message['id'] ?? null;
            $body        = $message['text']['body']
                ?? ('[' . ($message['type'] ?? 'unsupported') . ' message — view in WhatsApp Business]');
            $profileName = $contact['profile']['name'] ?? null;

            // Meta retries webhooks on any non-2xx or timeout — de-dupe on
            // its own message id rather than log the same inbound twice.
            if ($waMessageId && CustomerCommunication::where('whatsapp_message_id', $waMessageId)->exists()) {
                return;
            }

            // Loose match on the trailing digits — phone numbers are stored
            // in free-text form (with/without +, spaces, dashes) elsewhere in
            // this app, not normalized to E.164. Strip the same punctuation
            // from the STORED value inside the query too (not just the
            // inbound number) — otherwise "+233 24 123 4567" in the database
            // never matches "233241234567" from the webhook, since the
            // spaces break a plain LIKE substring match. A full phone
            // normalization pass across existing customer/quote data is a
            // separate, bigger effort, not done here.
            $tail        = substr(preg_replace('/\D+/', '', $phone) ?? $phone, -9);
            $strippedSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
            $customer    = $tail ? Customer::whereRaw("{$strippedSql} LIKE ?", ["%{$tail}"])->first() : null;
            $quote       = $customer
                ? QuoteRequest::where('customer_id', $customer->id)->latest()->first()
                : ($tail ? QuoteRequest::whereRaw("{$strippedSql} LIKE ?", ["%{$tail}"])->latest()->first() : null);

            $comm = CustomerCommunication::create([
                'customer_id'         => $customer?->id,
                'quote_request_id'    => $quote?->id,
                'phone_number'        => $phone,
                'type'                => 'whatsapp',
                'direction'           => 'inbound',
                'channel'             => 'whatsapp',
                'body'                => $body,
                'whatsapp_message_id' => $waMessageId,
                'whatsapp_status'     => 'received',
                'status'              => 'completed',
                'completed_at'        => now(),
            ]);

            if (! $customer && ! $quote) {
                // First-ever contact from this number — feed it into the
                // same lead pipeline as the website form, not a silo.
                $this->createLeadFromWhatsApp($phone, $profileName, $body);
                return;
            }

            AdminNotificationService::notifyPermission(
                permission:  'crm.view',
                type:        'whatsapp_message_received',
                title:       'New WhatsApp message',
                body:        ($profileName ?: $phone) . ': ' . Str::limit($body, 120),
                actionUrl:   $customer ? "/admin/customers/{$customer->id}?tab=communications" : "/admin/quotes/{$quote->id}",
                severity:    'info',
                relatedType: 'customer_communication',
                relatedId:   $comm->id,
                dedupeKey:   "whatsapp_message_received:communication:{$comm->id}",
            );
        } catch (\Throwable $e) {
            Log::error('WhatsApp inbound message handling failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * First contact from a number with no matching customer/quote — creates
     * a QuoteRequest the same way the website form and tyre-wholesaler
     * landing page do, so it goes through the same CRM-2 quality gate and
     * CRM-3B notification fan-out staff already rely on. WhatsApp gives no
     * e-mail address, ever — quote_requests.email is NOT NULL, so a
     * deterministic, obviously-synthetic placeholder is used rather than
     * loosening that constraint (which many other places assume is always
     * a real, usable address). Staff replace it with a real one during
     * triage once the customer provides one.
     */
    private function createLeadFromWhatsApp(string $phone, ?string $profileName, string $message): void
    {
        $quality = $this->qualityService->score(['notes' => $message, 'email' => '']);

        $quote = QuoteRequest::create([
            'ref_number'           => 'OKL-QR-' . substr((string) now()->timestamp, -6) . '-' . strtoupper(Str::random(3)),
            'full_name'            => $profileName ?: ('WhatsApp Contact ' . $phone),
            'email'                => "whatsapp+{$phone}@no-email.okelcor.internal",
            'phone'                => $phone,
            'country'              => 'Not specified',
            'tyre_category'        => 'mixed',
            'quantity'             => 'Not specified',
            'delivery_location'    => 'Not specified',
            'notes'                => $message,
            'status'               => 'new',
            'review_status'        => $quality['review_status'],
            'quality_score'        => $quality['quality_score'],
            'quality_flags'        => $quality['quality_flags'],
            'qualification_status' => $quality['review_status'],
            'lead_source'          => 'whatsapp',
        ]);

        $isNeedsReview = $quality['review_status'] === 'needs_review';

        AdminNotificationService::notifyPermission(
            permission:  'quotes.manage',
            type:        'whatsapp_lead_received',
            title:       'New WhatsApp inquiry',
            body:        sprintf('%s: %s', $quote->full_name, Str::limit($message, 120)),
            actionUrl:   "/admin/quotes/{$quote->id}",
            severity:    $isNeedsReview ? 'warning' : 'info',
            relatedType: 'quote_request',
            relatedId:   $quote->id,
            metadata:    ['ref_number' => $quote->ref_number, 'quality_score' => $quality['quality_score']],
            dedupeKey:   "whatsapp_lead_received:quote_request:{$quote->id}",
        );
    }

    private function handleStatusUpdate(array $status): void
    {
        try {
            $waMessageId = $status['id'] ?? null;
            $newStatus   = $status['status'] ?? null; // sent | delivered | read | failed

            if (! $waMessageId || ! $newStatus) {
                return;
            }

            CustomerCommunication::where('whatsapp_message_id', $waMessageId)
                ->update(['whatsapp_status' => $newStatus]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp status update handling failed', ['error' => $e->getMessage()]);
        }
    }

    private function verifySignature(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');
        if (! $secret) {
            // Can't verify without a configured secret — refuse rather than
            // trust an unverifiable payload.
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, substr($header, 7));
    }
}
