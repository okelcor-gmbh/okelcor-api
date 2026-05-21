<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Sanitizes article rich-text HTML before it is persisted.
 *
 * Allowed structure: headings, inline formatting, lists, blockquotes,
 * tables, links, images, dividers, and CTA blocks.
 * Stripped unconditionally: script, iframe, form, style elements,
 * event handlers (onclick/onerror/etc.), javascript: URLs, data: URLs.
 */
class ArticleHtmlSanitizer
{
    /**
     * Sanitize rich-text HTML from the editor.
     *
     * Returns the sanitized HTML string. On purifier failure, logs a warning and
     * throws a RuntimeException so the controller can return a 422 instead of 500.
     */
    public function sanitize(string $html): string
    {
        if (empty(trim($html))) {
            return '';
        }

        $config = \HTMLPurifier_Config::createDefault();

        // ── Disable file-based definition cache ───────────────────────────────
        // Avoids permission errors on production when the cache dir is not writable.
        // HTMLPurifier rebuilds its definition per request (negligible cost for article saves).
        $config->set('Cache.DefinitionImpl', null);

        // ── Allowed elements ──────────────────────────────────────────────────
        // Covers all TipTap output: headings, inline marks, lists, blockquotes,
        // tables, links, images, code blocks, and CTA div blocks.
        $config->set('HTML.Allowed',
            'h1,h2,h3,h4,h5,h6,' .
            'p,br,hr,' .
            'strong,em,u,s,code[class],mark,sub,sup,' .
            'pre[class],' .
            'blockquote,q,' .
            'ul,ol,li,' .
            'table[class],thead,tbody,tfoot,tr,th[colspan|rowspan|style],td[colspan|rowspan|style],' .
            'a[href|target|rel|title],' .
            'img[src|alt|width|height|class|loading],' .
            'div[class|data-type|data-cta-title|data-cta-text|data-cta-url|data-cta-label],' .
            'span[class|style],' .
            'figure[class],figcaption'
        );

        // ── Allowed URL schemes ───────────────────────────────────────────────
        // Blocks javascript: and data: URLs entirely.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // ── Link safety ───────────────────────────────────────────────────────
        $config->set('HTML.TargetBlank', false);
        $config->set('HTML.TargetNoopener', true);

        // ── Allowed CSS in style attributes ───────────────────────────────────
        // Limited to table cell alignment used by TipTap's table extension.
        $config->set('CSS.AllowedProperties', ['text-align', 'vertical-align', 'width', 'min-width']);

        // ── Image safety ──────────────────────────────────────────────────────
        // Restrict img[src] to the app domain so editors cannot embed arbitrary
        // external images. Editors must use the body-image upload endpoint.
        // Note: this does NOT block external a[href] hyperlinks — only embedded resources.
        $appDomain = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        if ($appDomain) {
            $config->set('URI.Host', $appDomain);
            $config->set('URI.DisableExternalResources', true);
        }

        $config->set('HTML.SafeIframe', false);

        try {
            $purifier = new \HTMLPurifier($config);
            $clean    = $purifier->purify($html);

            // If purification returns empty but the input was not empty, it means
            // all content was stripped (e.g. all tags disallowed). Return empty
            // so the controller can decide whether to reject the body.
            return $clean;
        } catch (\Throwable $e) {
            Log::warning('ArticleHtmlSanitizer: HTMLPurifier failed', [
                'error' => $e->getMessage(),
            ]);
            // Re-throw as a plain RuntimeException so the controller surfaces a
            // 422 instead of an unhandled 500.
            throw new \RuntimeException(
                'Article body could not be sanitized. Please check the content and try again.',
                0,
                $e
            );
        }
    }

    /**
     * Convert a legacy JSON body array (array of paragraph strings) to safe HTML.
     * Each element becomes a <p> tag; strings that are already plain text are
     * escaped. This is the one-way migration path for pre-rich-editor articles.
     */
    public function jsonArrayToHtml(array $paragraphs): string
    {
        $parts = [];
        foreach ($paragraphs as $line) {
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            $parts[] = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        return implode("\n", $parts);
    }
}
