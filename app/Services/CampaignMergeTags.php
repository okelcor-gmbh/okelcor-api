<?php

namespace App\Services;

use App\Models\MarketingContact;

/**
 * Per-recipient personalization tokens for bulk campaigns.
 *
 * Written as `[[FIRST_NAME]]` anywhere in a campaign — a block's text, a
 * button's URL, a subject line. Substituted at send time, once per recipient,
 * so a single rendered body serves the whole send.
 *
 * Every tag supports a fallback: `[[FIRST_NAME|there]]` renders "there" when
 * the contact has no first name. That matters more than it looks — most of the
 * imported list has an email and nothing else, and "Hi ," going out to 1,700
 * businesses is the kind of mistake a non-technical user can't be expected to
 * anticipate. A tag with no fallback resolves to an empty string, never to the
 * literal token.
 */
class CampaignMergeTags
{
    /** tag => [label, description, sample value used in previews] */
    public const TAGS = [
        'FIRST_NAME' => ['First name', "The contact's first name.", 'Anna'],
        'LAST_NAME'  => ['Last name', "The contact's last name.", 'Novak'],
        'FULL_NAME'  => ['Full name', 'First and last name together.', 'Anna Novak'],
        'COMPANY'    => ['Company', "The contact's company name.", 'Zagreb Tyres d.o.o.'],
        'EMAIL'      => ['Email', "The contact's email address.", 'anna@example.com'],
        'COUNTRY'    => ['Country', "The contact's country.", 'Croatia'],
        'MARKET'     => ['Market', "The contact's primary market.", 'croatia'],
        // Deliberately not shaped like a real unsubscribe URL: this value only
        // ever appears in previews and test sends, and something that looks
        // live invites a click that does nothing.
        'UNSUBSCRIBE_URL' => ['Unsubscribe link', 'The personal unsubscribe link. Always included in the footer automatically — only use this if you want an extra link of your own.', 'https://okelcor.com/#unsubscribe-preview-only'],
    ];

    /**
     * Replaces every tag in $content using one contact's real values.
     */
    public function apply(string $content, MarketingContact $contact, string $unsubscribeUrl): string
    {
        return $this->substitute($content, [
            'FIRST_NAME'      => (string) ($contact->first_name ?? ''),
            'LAST_NAME'       => (string) ($contact->last_name ?? ''),
            'FULL_NAME'       => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'COMPANY'         => (string) ($contact->company ?? ''),
            'EMAIL'           => (string) $contact->email,
            'COUNTRY'         => (string) ($contact->country ?? ''),
            'MARKET'          => (string) ($contact->market ?? ''),
            'UNSUBSCRIBE_URL' => $unsubscribeUrl,
        ]);
    }

    /**
     * Fills tags with the sample values above, for the editor preview — so the
     * marketer sees "Hi Anna," rather than raw tokens and can tell at a glance
     * whether a tag is spelled correctly.
     */
    public function applySamples(string $content): string
    {
        $values = [];
        foreach (self::TAGS as $tag => [, , $sample]) {
            $values[$tag] = $sample;
        }

        return $this->substitute($content, $values);
    }

    /**
     * Tags used in $content that aren't real tags — surfaced by the preview so a
     * typo like `[[FIRSTNAME]]` is caught before the send, not after it silently
     * left a blank in 1,700 emails.
     *
     * @return array<int, string>
     */
    public function unknownTags(string $content): array
    {
        preg_match_all('/\[\[\s*([A-Za-z0-9_]+)\s*(?:\|[^\]]*)?\]\]/', $content, $matches);

        $used    = array_unique($matches[1] ?? []);
        $unknown = array_values(array_diff($used, array_keys(self::TAGS)));

        return $unknown;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function substitute(string $content, array $values): string
    {
        return preg_replace_callback(
            '/\[\[\s*([A-Za-z0-9_]+)\s*(?:\|([^\]]*))?\]\]/',
            function ($m) use ($values) {
                $tag      = strtoupper($m[1]);
                $fallback = $m[2] ?? '';

                if (! array_key_exists($tag, $values)) {
                    // Leave an unknown tag alone rather than blanking it: it
                    // shows up in the preview and in unknownTags() instead of
                    // vanishing silently.
                    return $m[0];
                }

                $value = trim($values[$tag]);

                return $value !== '' ? $value : trim($fallback);
            },
            $content
        );
    }
}
