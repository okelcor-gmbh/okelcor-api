<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use Illuminate\Support\Str;

/**
 * Processes one inbound e-mail message (already normalized into a plain
 * array — see the shape below) so a customer's reply to a system-sent
 * e-mail lands in the admin panel, not only in the sending admin's
 * personal Outlook.
 *
 * Deliberately transport-agnostic: this class doesn't know or care whether
 * the message arrived via IMAP polling or a Cloudflare Email Worker
 * webhook — both of this app's inbound-capture attempts (IMAP, then
 * Cloudflare) normalize into the same shape and call process() unchanged,
 * which is why this logic survived every pivot fully tested.
 *
 * Expected `$message` shape:
 *   [
 *     'from' => ['emailAddress' => ['address' => '...', 'name' => '...']],
 *     'toRecipients' => [['emailAddress' => ['address' => '...']], ...],
 *     'subject' => '...',
 *     'internetMessageId' => '...',              // may include <angle brackets>
 *     'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => '...'], ...],
 *     'body' => ['contentType' => 'html'|'text', 'content' => '...'],
 *   ]
 *
 * Matching a reply to its original message, in order of reliability:
 *   1. Plus-addressing — the reply's own To: is {base}+{uuid}@..., which
 *      directly names the original outbound message_id. Most reliable;
 *      survives header stripping some corporate mail gateways do.
 *   2. In-Reply-To header — standard, but occasionally stripped/mangled.
 *   3. Sender e-mail address — matches an existing Customer/QuoteRequest
 *      even with no thread context at all (e.g. a fresh, unsolicited
 *      e-mail to the inbound address rather than a reply).
 *
 * A message that matches none of the above — and isn't from Okelcor's own
 * domain (see isOwnDomainSender) — is treated as a brand-new inquiry and
 * fed into the same lead pipeline as the website form / the WhatsApp
 * webhook (CRM-2 quality gate + CRM-3B notification), not a separate silo.
 */
class InboundEmailProcessor
{
    public function __construct(
        private readonly RichEmailHtmlSanitizer $sanitizer,
        private readonly InquiryQualityService $qualityService,
    ) {}

    public function process(array $message): void
    {
        $fromEmail = isset($message['from']['emailAddress']['address'])
            ? strtolower($message['from']['emailAddress']['address'])
            : null;
        $fromName = $message['from']['emailAddress']['name'] ?? $fromEmail;

        if (! $fromEmail) {
            return; // unparseable sender — nothing safe to link this to
        }

        if ($this->isOwnDomainSender($fromEmail)) {
            // The inbound mailbox is shared with other automated system
            // mail (ORDER_EMAIL/QUOTE_EMAIL/CRM_DIGEST_EMAIL all point at
            // support@ too) — an order-received/quote-received/digest
            // notification sent BY this app TO this mailbox must never be
            // mistaken for a customer message and spawn a bogus lead.
            return;
        }

        $incomingMessageId = $this->stripAngleBrackets($message['internetMessageId'] ?? null);

        // De-dupe in case the same message is ever delivered twice.
        if ($incomingMessageId && CustomerCommunication::where('message_id', $incomingMessageId)->exists()) {
            return;
        }

        $subject     = (string) ($message['subject'] ?: '(no subject)');
        $contentType = $message['body']['contentType'] ?? 'text';
        $rawContent  = $message['body']['content'] ?? '';

        $bodyClean = strtolower($contentType) === 'html'
            ? $this->sanitizer->sanitize($rawContent, 'communications/' . Str::uuid())
            : nl2br(e(trim($rawContent)));

        $parentMessageId = $this->extractPlusAddressedMessageId($message)
            ?? $this->extractInReplyToHeader($message);

        $parent = $parentMessageId
            ? CustomerCommunication::where('message_id', $parentMessageId)->first()
            : null;

        $customer = $parent?->customer_id ? Customer::find($parent->customer_id) : Customer::where('email', $fromEmail)->first();
        $quote    = $parent?->quote_request_id
            ? QuoteRequest::find($parent->quote_request_id)
            : ($customer
                ? QuoteRequest::where('customer_id', $customer->id)->latest()->first()
                : QuoteRequest::where('email', $fromEmail)->latest()->first());

        $comm = CustomerCommunication::create([
            'customer_id'      => $customer?->id ?? $parent?->customer_id,
            'quote_request_id' => $quote?->id ?? $parent?->quote_request_id,
            'order_id'         => $parent?->order_id,
            'type'             => 'email',
            'direction'        => 'inbound',
            'channel'          => 'email',
            'subject'          => $subject,
            'body'             => $bodyClean,
            'message_id'       => $incomingMessageId,
            'in_reply_to'      => $parentMessageId,
            'status'           => 'completed',
            'completed_at'     => now(),
        ]);

        if (! $customer && ! $quote && ! $parent) {
            // Genuinely new correspondent — feed into the same lead
            // pipeline as the website form / WhatsApp, not a silo. Unlike
            // WhatsApp, a real e-mail address is available here.
            $lead = $this->createLeadFromEmail($fromEmail, $fromName, $subject, strip_tags($bodyClean));
            $comm->update(['quote_request_id' => $lead->id]);
            return;
        }

        $summary   = sprintf('%s replied: %s', $fromName, Str::limit(strip_tags($bodyClean), 120));
        $actionUrl = $customer ? "/admin/customers/{$customer->id}?tab=communications" : ($quote ? "/admin/quotes/{$quote->id}" : null);

        // Notify the specific admin who sent the original message when
        // known — more useful than a blanket fan-out — falling back to
        // every crm.view admin when it can't be resolved (e.g. matched by
        // sender e-mail only, no thread context).
        if ($parent?->admin_user_id && AdminUser::where('id', $parent->admin_user_id)->where('is_active', true)->exists()) {
            AdminNotificationService::notifyUser(
                adminUserId: $parent->admin_user_id,
                type: 'email_reply_received',
                title: 'Customer replied by e-mail',
                body: $summary,
                actionUrl: $actionUrl,
                severity: 'info',
                relatedType: 'customer_communication',
                relatedId: $comm->id,
            );
        } else {
            AdminNotificationService::notifyPermission(
                permission: 'crm.view',
                type: 'email_reply_received',
                title: 'Customer replied by e-mail',
                body: $summary,
                actionUrl: $actionUrl,
                severity: 'info',
                relatedType: 'customer_communication',
                relatedId: $comm->id,
                dedupeKey: "email_reply_received:communication:{$comm->id}",
            );
        }
    }

    private function createLeadFromEmail(string $email, string $name, string $subject, string $message): QuoteRequest
    {
        $quality = $this->qualityService->score(['notes' => $message, 'email' => $email]);

        $quote = QuoteRequest::create([
            'ref_number'           => 'OKL-QR-' . substr((string) now()->timestamp, -6) . '-' . strtoupper(Str::random(3)),
            'full_name'            => $name,
            'email'                => $email,
            'country'              => 'Not specified',
            'tyre_category'        => 'mixed',
            'quantity'             => 'Not specified',
            'delivery_location'    => 'Not specified',
            'notes'                => trim($subject . "\n\n" . $message),
            'status'               => 'new',
            'review_status'        => $quality['review_status'],
            'quality_score'        => $quality['quality_score'],
            'quality_flags'        => $quality['quality_flags'],
            'qualification_status' => $quality['review_status'],
            'lead_source'          => 'inbound_email',
        ]);

        AdminNotificationService::notifyPermission(
            permission:  'quotes.manage',
            type:        'inbound_email_lead_received',
            title:       'New e-mail inquiry',
            body:        sprintf('%s: %s', $quote->full_name, Str::limit($message, 120)),
            actionUrl:   "/admin/quotes/{$quote->id}",
            severity:    $quality['review_status'] === 'needs_review' ? 'warning' : 'info',
            relatedType: 'quote_request',
            relatedId:   $quote->id,
            metadata:    ['ref_number' => $quote->ref_number, 'quality_score' => $quality['quality_score']],
            dedupeKey:   "inbound_email_lead_received:quote_request:{$quote->id}",
        );

        return $quote;
    }

    /**
     * True when the sender's address is on Okelcor's own domain (derived
     * from mail.from.address, e.g. "okelcor.com") — i.e. this app's own
     * automated notifications, or a staff member's internal address,
     * neither of which is ever a customer reply. Public (not private) so
     * this safety-critical check can be tested directly.
     */
    public function isOwnDomainSender(string $email): bool
    {
        $ownDomain = strtolower((string) (
            config('services.mail_inbound.own_domain')
                ?: (explode('@', config('mail.from.address', ''))[1] ?? '')
        ));

        if ($ownDomain === '') {
            return false;
        }

        return str_ends_with(strtolower($email), '@' . $ownDomain);
    }

    /**
     * If this reply's To: address is a plus-addressed variant of the
     * configured inbound base address ({base}+{uuid}@{domain}),
     * reconstructs the original outbound message_id ({uuid}@{domain})
     * directly — no header parsing needed, and immune to headers a mail
     * gateway may have stripped.
     */
    public function extractPlusAddressedMessageId(array $message): ?string
    {
        $baseAddress = config('services.mail_inbound.address');
        if (! $baseAddress || ! str_contains($baseAddress, '@')) {
            return null;
        }

        [$baseLocal, $baseDomain] = explode('@', $baseAddress, 2);
        $messageIdDomain = config('services.mail_inbound.message_id_domain', 'okelcor.com');

        foreach ($message['toRecipients'] ?? [] as $to) {
            $mail = $to['emailAddress']['address'] ?? '';
            if (! str_contains($mail, '@')) {
                continue;
            }

            [$local, $host] = explode('@', $mail, 2);
            if (strcasecmp($host, $baseDomain) !== 0 || ! str_starts_with($local, $baseLocal . '+')) {
                continue;
            }

            $tag = substr($local, strlen($baseLocal) + 1);
            if ($tag !== '') {
                return "{$tag}@{$messageIdDomain}";
            }
        }

        return null;
    }

    public function extractInReplyToHeader(array $message): ?string
    {
        foreach ($message['internetMessageHeaders'] ?? [] as $header) {
            if (strcasecmp($header['name'] ?? '', 'In-Reply-To') === 0) {
                return $this->stripAngleBrackets($header['value'] ?? null);
            }
        }

        return null;
    }

    private function stripAngleBrackets(?string $value): ?string
    {
        return $value ? trim(str_replace(['<', '>'], '', $value)) : null;
    }
}
