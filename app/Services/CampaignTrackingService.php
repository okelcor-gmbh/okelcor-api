<?php

namespace App\Services;

use App\Models\BulkEmailCampaignRecipient;

/**
 * Instruments a personalized campaign email for engagement tracking:
 * an open pixel per recipient, and every outbound link rewritten through
 * a signed redirect so a click ("completion") is recorded before the
 * reader lands where they were going.
 *
 * The redirect is HMAC-signed over (token|url) with the app key: the
 * click endpoint redirects ONLY to URLs this service put in an email,
 * so the tracker can never be used as an open redirect.
 */
class CampaignTrackingService
{
    /**
     * @param  string  $html            the personalized HTML about to be sent
     * @param  string  $unsubscribeUrl  never rewritten — opting out must not depend on the tracker
     */
    public function instrument(string $html, BulkEmailCampaignRecipient $recipient, string $unsubscribeUrl): string
    {
        $token = $recipient->tracking_token;
        if (! $token) {
            return $html;   // pre-tracking campaign rows: send untouched
        }

        // Rewrite http(s) links through the click endpoint.
        $html = preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $m) use ($token, $unsubscribeUrl) {
                $target = html_entity_decode($m[1]);

                if ($target === $unsubscribeUrl || str_contains($target, '/marketing-contacts/unsubscribe/')) {
                    return $m[0];
                }

                return 'href="' . e($this->clickUrl($token, $target)) . '"';
            },
            $html
        ) ?? $html;

        // The open pixel, last thing in the body.
        $pixel = '<img src="' . e(url("/api/v1/campaign/open/{$token}.gif")) . '" width="1" height="1" alt="" style="display:block;border:0;" />';
        $html = str_contains($html, '</body>')
            ? str_replace('</body>', $pixel . '</body>', $html)
            : $html . $pixel;

        return $html;
    }

    public function clickUrl(string $token, string $target): string
    {
        return url("/api/v1/campaign/click/{$token}")
            . '?u=' . urlencode($target)
            . '&s=' . $this->sign($token, $target);
    }

    public function sign(string $token, string $target): string
    {
        return substr(hash_hmac('sha256', $token . '|' . $target, (string) config('app.key')), 0, 16);
    }

    public function verify(string $token, string $target, string $signature): bool
    {
        return hash_equals($this->sign($token, $target), $signature);
    }
}
