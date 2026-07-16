<?php

namespace App\Http\Controllers;

use App\Services\InboundEmailProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives inbound e-mail from a Cloudflare Email Worker — a subdomain
 * (e.g. reply.okelcor.com) has Cloudflare Email Routing pointed at a small
 * Worker script (see cloudflare-worker/), which parses the raw MIME e-mail
 * and POSTs the parsed fields here. This is the third design for inbound
 * capture in this app's history (IMAP direct → Microsoft Graph → Exchange
 * redirect + IMAP → this), chosen after the IMAP-redirect approach
 * couldn't be gotten working end to end. See EMAIL_INBOUND_SETUP.md.
 *
 *   POST /api/v1/webhooks/email-inbound
 *
 * No admin/customer auth (Cloudflare calls this directly) — protected
 * instead by verifying an HMAC-SHA256 signature over the raw request body,
 * keyed with a shared secret only this app and the Worker know. Same
 * security boundary already applied to the Stripe and WhatsApp webhooks.
 *
 * Expected JSON payload from the Worker:
 *   {
 *     "from": {"address": "...", "name": "..."},
 *     "to": [{"address": "...", "name": "..."}, ...],
 *     "subject": "...",
 *     "html": "...",       // may be empty
 *     "text": "...",       // may be empty
 *     "messageId": "...",  // may include <angle brackets>
 *     "inReplyTo": "..."   // may include <angle brackets>, may be absent
 *   }
 */
class EmailInboundWebhookController extends Controller
{
    public function __construct(private readonly InboundEmailProcessor $processor) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Inbound e-mail webhook rejected: invalid or missing signature');
            return response()->json(['status' => 'invalid signature'], 403);
        }

        if (! config('services.mail_inbound.enabled')) {
            return response()->json(['status' => 'disabled']);
        }

        try {
            $this->processor->process($this->normalize($request->json()->all()));
        } catch (\Throwable $e) {
            Log::error('[inbound_email_webhook_processing_failed]', ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Converts the Cloudflare Worker's JSON payload into the same plain
     * array shape InboundEmailProcessor already expects (and was already
     * tested against) — deliberately the same shape a Microsoft Graph
     * response would have had, so the processor never needed to change
     * across any of this feature's transport pivots.
     */
    private function normalize(array $payload): array
    {
        $htmlBody = (string) ($payload['html'] ?? '');
        $textBody = (string) ($payload['text'] ?? '');

        $headers = [];
        if (! empty($payload['inReplyTo'])) {
            $headers[] = ['name' => 'In-Reply-To', 'value' => $payload['inReplyTo']];
        }

        return [
            'from' => [
                'emailAddress' => [
                    'address' => $payload['from']['address'] ?? null,
                    'name'    => $payload['from']['name'] ?? ($payload['from']['address'] ?? null),
                ],
            ],
            'toRecipients' => array_map(
                fn (array $to) => ['emailAddress' => ['address' => $to['address'] ?? '']],
                $payload['to'] ?? []
            ),
            'subject'                => $payload['subject'] ?? '',
            'internetMessageId'      => $payload['messageId'] ?? null,
            'internetMessageHeaders' => $headers,
            'body' => [
                'contentType' => $htmlBody !== '' ? 'html' : 'text',
                'content'     => $htmlBody !== '' ? $htmlBody : $textBody,
            ],
        ];
    }

    private function verifySignature(Request $request): bool
    {
        $secret = config('services.mail_inbound.webhook_secret');
        if (! $secret) {
            return false;
        }

        $provided = (string) $request->header('X-Webhook-Signature', '');
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
