<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * Reads the shared mailbox via plain IMAP with a username/password —
 * deliberately not Microsoft Graph. support@okelcor.com is redirected (an
 * Exchange inbox rule, not a Microsoft-side integration) to a separate,
 * non-Microsoft mailbox this connects to instead, since Microsoft has fully
 * retired Basic Authentication for IMAP/POP/SMTP on Exchange Online itself
 * — see EMAIL_INBOUND_SETUP.md for exactly how the redirect is set up and
 * why. `webklex/php-imap` is a pure-PHP IMAP client, deliberately not the
 * `ext-imap` PHP extension, which isn't guaranteed present on this
 * shared-hosting PHP build.
 *
 * Degrades cleanly (['error' => ...]) when unconfigured or on any
 * connection/fetch failure, same pattern as every other external
 * integration in this app. Never throws; callers check the 'error' key.
 */
class ImapInboundMailService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.mail_inbound.host')
            && (bool) config('services.mail_inbound.username')
            && (bool) config('services.mail_inbound.password');
    }

    /**
     * @return array{error:string}|array{messages: MessageCollection}
     */
    public function fetchUnseenMessages(): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Inbound IMAP mailbox is not configured (missing host/username/password).'];
        }

        try {
            $client = (new ClientManager())->make([
                'host'          => config('services.mail_inbound.host'),
                'port'          => (int) config('services.mail_inbound.port'),
                'encryption'    => config('services.mail_inbound.encryption'),
                'validate_cert' => true,
                'username'      => config('services.mail_inbound.username'),
                'password'      => config('services.mail_inbound.password'),
                'protocol'      => 'imap',
            ]);
            $client->connect();

            $folder = $client->getFolder('INBOX');

            return ['messages' => $folder->query()->whereUnseen()->get()];
        } catch (\Throwable $e) {
            Log::error('ImapInboundMailService: connect/fetch failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function markAsSeen(Message $message): array
    {
        try {
            $message->setFlag('Seen');
            return ['success' => true];
        } catch (\Throwable $e) {
            Log::warning('ImapInboundMailService: mark-as-seen failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Normalizes a webklex Message into the same plain-array shape the
     * message-parsing logic (FetchInboundEmails::processMessage and
     * friends) already uses — deliberately matching the shape Microsoft
     * Graph's REST API returns, so that logic didn't need to change at all
     * when the transport switched from Graph back to IMAP.
     */
    public function normalize(Message $message): array
    {
        $from = $message->from->first();

        $htmlBody = $message->getHTMLBody();
        $textBody = $message->getTextBody();

        return [
            'from' => [
                'emailAddress' => [
                    'address' => $from?->mail,
                    'name'    => $from?->personal ?: $from?->mail,
                ],
            ],
            'toRecipients' => array_map(
                fn ($addr) => ['emailAddress' => ['address' => $addr->mail]],
                $message->to->all()
            ),
            'subject'           => $message->subject->first(),
            'internetMessageId' => $message->message_id->first(),
            'internetMessageHeaders' => $message->in_reply_to->first()
                ? [['name' => 'In-Reply-To', 'value' => $message->in_reply_to->first()]]
                : [],
            'body' => [
                'contentType' => $htmlBody !== '' ? 'html' : 'text',
                'content'     => $htmlBody !== '' ? $htmlBody : $textBody,
            ],
        ];
    }
}
