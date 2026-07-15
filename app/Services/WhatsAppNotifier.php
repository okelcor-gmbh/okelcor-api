<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCommunication;
use Illuminate\Support\Facades\Log;

/**
 * Template-based automated WhatsApp notifications — the WhatsApp twin of
 * CustomerNotifier's e-mail triggers, for events that happen outside the
 * customer's own 24h reply window (so a template message, not free text, is
 * required). Every send is opt-in gated via CustomerNotifier::wantsWhatsApp()
 * and logged to the same customer_communications table the admin composer
 * and inbound webhook both use — one unified history regardless of channel.
 *
 * Template names below are placeholders that must exist, approved, in Meta
 * Business Manager before any of this actually sends anything — see
 * WHATSAPP_SETUP.md for the exact content to submit for each one. An
 * unapproved/missing template just fails gracefully (logged, no crash),
 * same as every other external API integration in this app.
 *
 * Only wired into one trigger so far (order shipped, in
 * AdminOrderController::notifyShipmentStatus) as a concrete, working
 * example — extending to the others below is the same one-line call at the
 * matching CustomerNotifier::notify()/notifyByEmail() call site, once its
 * template is approved. Not wired everywhere yet on purpose: there is no
 * value in calling a template that doesn't exist.
 */
class WhatsAppNotifier
{
    /**
     * type => [Meta template name, language code]. Extend this list as more
     * templates get approved — nothing else needs to change to add one.
     */
    public const TEMPLATES = [
        'order_shipped'    => ['name' => 'okelcor_order_shipped', 'language' => 'en_US'],
        'order_delivered'  => ['name' => 'okelcor_order_delivered', 'language' => 'en_US'],
        'payment_reminder' => ['name' => 'okelcor_payment_reminder', 'language' => 'en_US'],
        'proposal_ready'   => ['name' => 'okelcor_proposal_ready', 'language' => 'en_US'],
        'quote_ready'      => ['name' => 'okelcor_quote_ready', 'language' => 'en_US'],
    ];

    /**
     * @param  array<int, string>  $bodyParams  Positional {{1}}, {{2}}... values for the template body
     */
    public static function notifyTemplate(
        Customer $customer,
        string $type,
        array $bodyParams,
        ?int $quoteRequestId = null,
        ?int $orderId = null
    ): void {
        if (! CustomerNotifier::wantsWhatsApp($customer)) {
            return;
        }

        $template = self::TEMPLATES[$type] ?? null;
        if (! $template) {
            return;
        }

        try {
            $result = app(WhatsAppService::class)->sendTemplate(
                $customer->phone, $template['name'], $template['language'], $bodyParams
            );
            $sent = ! isset($result['error']);

            CustomerCommunication::create([
                'customer_id'            => $customer->id,
                'quote_request_id'       => $quoteRequestId,
                'order_id'               => $orderId,
                'phone_number'           => $customer->phone,
                'type'                   => 'whatsapp',
                'direction'              => 'outbound',
                'channel'                => 'whatsapp',
                'body'                   => 'Template "' . $template['name'] . '": ' . implode(' / ', $bodyParams),
                'whatsapp_message_id'    => $result['message_id'] ?? null,
                'whatsapp_template_name' => $template['name'],
                'whatsapp_status'        => $sent ? 'sent' : 'failed',
                'status'                 => $sent ? 'sent' : 'failed',
                'completed_at'           => $sent ? now() : null,
                'metadata'               => $sent ? null : ['error' => $result['error']],
            ]);

            if (! $sent) {
                Log::warning('WhatsAppNotifier: template send failed', [
                    'type' => $type, 'customer_id' => $customer->id, 'error' => $result['error'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsAppNotifier: send/log failed', [
                'type' => $type, 'customer_id' => $customer->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
