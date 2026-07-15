<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a Microsoft 365 / Exchange Online mailbox via Microsoft Graph —
 * NOT IMAP. Microsoft has fully retired Basic Authentication (plain
 * username/password) for IMAP/POP/SMTP on Exchange Online; any IMAP client
 * using a password will be rejected regardless of how correct the
 * credentials are. Graph, authenticated via OAuth2 client-credentials
 * (an Azure AD app registration, not a personal login), is the current,
 * actively-supported way to read mail programmatically — see
 * EMAIL_INBOUND_SETUP.md for the one-time Azure app registration this
 * requires.
 *
 * Endpoints used (Microsoft Graph v1.0):
 *
 *   Token:    POST https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token
 *             grant_type=client_credentials, scope=https://graph.microsoft.com/.default
 *
 *   Messages: GET /users/{mailbox}/mailFolders/inbox/messages
 *             ?$filter=isRead eq false&$top=50
 *             &$select=id,subject,from,toRecipients,body,internetMessageId,internetMessageHeaders
 *             Authorization: Bearer {token}
 *
 *   Mark read: PATCH /users/{mailbox}/messages/{id}   body: {"isRead": true}
 *
 * Requires the Application permission `Mail.ReadWrite` (admin-consented) —
 * `Mail.Read` alone isn't enough since marking messages read/processed
 * needs write access too. Degrades cleanly (['error' => ...]) when
 * unconfigured or on any API failure, same pattern as WhatsAppService.
 */
class MicrosoftGraphMailService
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const CACHE_KEY = 'ms_graph_mail_access_token';

    public function isConfigured(): bool
    {
        return (bool) config('services.mail_inbound.tenant_id')
            && (bool) config('services.mail_inbound.client_id')
            && (bool) config('services.mail_inbound.client_secret')
            && (bool) config('services.mail_inbound.address');
    }

    /**
     * Unread messages in the inbox, oldest-processed-first isn't guaranteed
     * by Graph — caller shouldn't assume ordering.
     *
     * @return array{error:string}|array{messages: array<int, array>}
     */
    public function fetchUnreadMessages(int $top = 50): array
    {
        $token = $this->getAccessToken();
        if (isset($token['error'])) {
            return $token;
        }

        $mailbox = config('services.mail_inbound.address');
        $url = self::GRAPH_BASE . "/users/{$mailbox}/mailFolders/inbox/messages";

        try {
            $response = Http::withToken($token['access_token'])
                ->acceptJson()
                ->get($url, [
                    '$filter'  => 'isRead eq false',
                    '$top'     => $top,
                    '$select'  => 'id,subject,from,toRecipients,body,internetMessageId,internetMessageHeaders',
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message') ?? $response->body();
                Log::warning('MicrosoftGraphMailService: fetch failed', ['status' => $response->status(), 'error' => $error]);
                return ['error' => $error];
            }

            return ['messages' => $response->json('value') ?? []];
        } catch (\Throwable $e) {
            Log::error('MicrosoftGraphMailService: fetch exception', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function markAsRead(string $messageId): array
    {
        $token = $this->getAccessToken();
        if (isset($token['error'])) {
            return $token;
        }

        $mailbox = config('services.mail_inbound.address');
        $url = self::GRAPH_BASE . "/users/{$mailbox}/messages/{$messageId}";

        try {
            $response = Http::withToken($token['access_token'])
                ->acceptJson()
                ->patch($url, ['isRead' => true]);

            if ($response->failed()) {
                $error = $response->json('error.message') ?? $response->body();
                Log::warning('MicrosoftGraphMailService: mark-as-read failed', ['error' => $error]);
                return ['error' => $error];
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('MicrosoftGraphMailService: mark-as-read exception', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Client-credentials tokens are valid ~1 hour — cached (minus a safety
     * margin) so a 5-minute poll doesn't re-authenticate every run.
     *
     * @return array{error:string}|array{access_token:string}
     */
    private function getAccessToken(): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Microsoft Graph inbound mail is not configured (missing tenant_id/client_id/client_secret/address).'];
        }

        $cached = Cache::get(self::CACHE_KEY);
        if ($cached) {
            return ['access_token' => $cached];
        }

        $tenant = config('services.mail_inbound.tenant_id');
        $url    = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()->post($url, [
                'grant_type'    => 'client_credentials',
                'client_id'     => config('services.mail_inbound.client_id'),
                'client_secret' => config('services.mail_inbound.client_secret'),
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

            if ($response->failed()) {
                $error = $response->json('error_description') ?? $response->body();
                Log::error('MicrosoftGraphMailService: token request failed', ['error' => $error]);
                return ['error' => $error];
            }

            $token   = $response->json('access_token');
            $expires = (int) ($response->json('expires_in') ?? 3600);

            // Cache for a bit less than the real expiry so we never use a
            // token in the last few seconds of its life.
            Cache::put(self::CACHE_KEY, $token, max(60, $expires - 120));

            return ['access_token' => $token];
        } catch (\Throwable $e) {
            Log::error('MicrosoftGraphMailService: token request exception', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
