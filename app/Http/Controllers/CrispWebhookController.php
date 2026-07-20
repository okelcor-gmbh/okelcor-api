<?php

namespace App\Http\Controllers;

use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * POST /api/v1/webhooks/crisp
 *
 * Answers the "is there a Crisp webhook for new messages" question from
 * the frontend note — yes: configure this URL under the Crisp dashboard
 * (Settings → your website → Integrations → Webhooks), subscribed to the
 * `message:send` event. That turns push notifications from polling into
 * genuine push the same way every other channel in this app already
 * works (customer replies, inbound e-mail, etc.) — nothing here fetches
 * conversations on a schedule.
 *
 * No admin/customer auth (Crisp calls this directly) — protected instead
 * by verifying an HMAC-SHA256 signature, same security boundary already
 * used for Stripe/WhatsApp/inbound-email webhooks in this app.
 *
 * Payload shape below (Crisp's `message:send` event) reflects Crisp's
 * publicly documented webhook format as of when this was written —
 * confirm against the actual payload Crisp's dashboard shows when the
 * webhook is configured (or the first real delivery, logged below on any
 * parse mismatch) before relying on it, since third-party payload shapes
 * can drift.
 */
class CrispWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Crisp webhook rejected: invalid or missing signature');
            return response()->json(['message' => 'invalid signature'], 403);
        }

        $payload = $request->json()->all();
        $event   = $payload['event'] ?? null;
        $data    = $payload['data'] ?? [];

        // Only "a visitor sent a message" needs an admin's attention —
        // operator-sent messages (from: 'operator') would otherwise echo
        // a push back to the very admin who just replied.
        if ($event === 'message:send' && ($data['from'] ?? null) === 'user') {
            $this->notifyAvailableAdmins($data);
        }

        return response()->json(['status' => 'ok']);
    }

    private function notifyAvailableAdmins(array $data): void
    {
        $sessionId = $data['session_id'] ?? null;
        $content   = is_string($data['content'] ?? null) ? $data['content'] : '';

        if (! $sessionId) {
            return;
        }

        AdminNotificationService::notifyPermission(
            permission:  'crm.view',
            type:        'crisp_message_received',
            title:       'New live chat message',
            body:        Str::limit($content, 120) ?: 'A visitor sent a new message.',
            actionUrl:   "/admin/live-chat/{$sessionId}",
            severity:    'info',
            relatedType: 'crisp_conversation',
            dedupeKey:   "crisp_message_received:{$sessionId}:" . now()->format('YmdHi'),
        );
    }

    private function verifySignature(Request $request): bool
    {
        $secret = config('services.crisp.webhook_secret');
        if (! $secret) {
            return false;
        }

        $provided = (string) $request->header('X-Crisp-Signature', '');
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
