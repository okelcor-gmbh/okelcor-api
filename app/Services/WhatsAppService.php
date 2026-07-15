<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API (Meta) client — sends outbound messages.
 * Inbound messages + delivery/read status land via the webhook instead
 * (see WhatsAppWebhookController), not through this class.
 *
 * Endpoint shape (Meta Graph API, Cloud API product):
 *
 *   POST {base_url}/{api_version}/{phone_number_id}/messages
 *   Authorization: Bearer {access_token}
 *   Content-Type: application/json
 *
 *   Text:     {"messaging_product":"whatsapp","to":"{E.164 digits, no +}","type":"text","text":{"body":"..."}}
 *   Template: {"messaging_product":"whatsapp","to":"...","type":"template","template":{"name":"...","language":{"code":"en_US"},"components":[...]}}
 *   Document: {"messaging_product":"whatsapp","to":"...","type":"document","document":{"link":"https://...","filename":"...","caption":"..."}}
 *
 *   Success response: {"messages":[{"id":"wamid.XXXX"}], ...}
 *   Error response:   {"error":{"message":"...","type":"...","code":131047,"error_data":{"details":"..."}}}
 *
 * Free-form text messages (`type: text`) only succeed inside the 24-hour
 * customer service window (the customer must have messaged in the last 24h)
 * — Meta returns error code 131047 ("re-engagement message") outside it.
 * Template messages have no such window but must reference a template
 * already approved in Meta Business Manager (see WHATSAPP_SETUP.md).
 *
 * Degrades cleanly (['error' => ...]) when unconfigured or on any API
 * failure — same pattern as GlsTrackingService/DhlTrackingService. Never
 * throws; callers check the 'error' key.
 */
class WhatsAppService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.whatsapp.phone_number_id')
            && (bool) config('services.whatsapp.access_token');
    }

    /**
     * Free-form text — only valid within the 24h customer service window.
     */
    public function sendText(string $to, string $body): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeRecipient($to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);
    }

    /**
     * Business-initiated template message — works outside the 24h window,
     * but `templateName` must already be approved in Meta Business Manager.
     * `bodyParams` are positional {{1}}, {{2}}... substitutions for the
     * template's body component, in order.
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'en_US', array $bodyParams = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeRecipient($to),
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if ($bodyParams) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $bodyParams),
            ]];
        }

        return $this->send($payload);
    }

    /**
     * A document (PDF proposal/invoice etc.) by public URL — reuses the same
     * trade-document/proposal download URLs already generated elsewhere in
     * the app. `link` must be publicly fetchable (Meta's servers download it
     * server-side); an authenticated URL will fail.
     */
    public function sendDocument(string $to, string $link, string $filename, ?string $caption = null): array
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeRecipient($to),
            'type'              => 'document',
            'document'          => array_filter([
                'link'     => $link,
                'filename' => $filename,
                'caption'  => $caption,
            ]),
        ]);
    }

    /**
     * True once the customer has messaged within the last 24h — the window
     * for free-form replies. Checked against our own log rather than asking
     * Meta (there's no endpoint for it); relies on inbound webhook events
     * having been recorded.
     */
    public function withinCustomerServiceWindow(?\Illuminate\Support\Carbon $lastInboundAt): bool
    {
        return $lastInboundAt !== null && $lastInboundAt->gt(now()->subHours(24));
    }

    /**
     * WhatsApp wants digits only (country code + number, no leading +,
     * spaces, or punctuation) — accepts whatever format the phone is stored
     * in elsewhere in the app and strips it down.
     */
    public function normalizeRecipient(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function send(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'WhatsApp is not configured (missing phone_number_id/access_token).'];
        }

        $url = rtrim(config('services.whatsapp.base_url'), '/') . '/'
            . config('services.whatsapp.api_version') . '/'
            . config('services.whatsapp.phone_number_id') . '/messages';

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                $error = $response->json('error.message') ?? $response->body();

                Log::warning('WhatsAppService: send failed', [
                    'status'  => $response->status(),
                    'error'   => $error,
                    'to'      => $payload['to'] ?? null,
                    'type'    => $payload['type'] ?? null,
                ]);

                return [
                    'error'      => $error,
                    'error_code' => $response->json('error.code'),
                ];
            }

            return [
                'message_id' => $response->json('messages.0.id'),
                'raw'        => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsAppService: request exception', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
