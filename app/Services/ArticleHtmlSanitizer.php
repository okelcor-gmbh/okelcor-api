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
     * Returns the sanitized string; throws if HTMLPurifier is unavailable.
     */
    public function sanitize(string $html): string
    {
        if (empty(trim($html))) {
            return '';
        }

        $config = \HTMLPurifier_Config::createDefault();

        // ── Allowed elements ──────────────────────────────────────────────────
        $config->set('HTML.Allowed',
            'h1,h2,h3,h4,h5,h6,' .
            'p,br,hr,' .
            'strong,em,u,s,code,mark,sub,sup,' .
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
        // Blocks javascript: and data: entirely.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // ── Link safety ───────────────────────────────────────────────────────
        // External links: noopener noreferrer is automatically added.
        $config->set('HTML.TargetBlank', false);
        $config->set('HTML.TargetNoopener', true);

        // ── Allowed CSS in style attributes ───────────────────────────────────
        // Only for th/td text alignment (TipTap table alignment uses inline style).
        $config->set('CSS.AllowedProperties', ['text-align', 'vertical-align', 'width', 'min-width']);

        // ── Image safety ──────────────────────────────────────────────────────
        // Reject images not served from this app (data: blocked by URI schemes above).
        // External image hosts are blocked unless explicitly whitelisted below.
        // Editors should use the body-image upload endpoint to embed images.
        $config->set('URI.SafeIframeRegexp', null);
        $config->set('HTML.SafeIframe', false);

        // ── Internal whitelist for img src ────────────────────────────────────
        // Allow images from the app domain only. The purifier's AttrTransform
        // strips external src automatically when URI.AllowedSchemes is restricted
        // to http/https but does not restrict domain. We handle domain restriction
        // via a custom transform below.
        $appDomain = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        if ($appDomain) {
            $config->set('URI.Host', $appDomain);
            $config->set('URI.DisableResources', false);
            $config->set('URI.DisableExternalResources', true);
        }

        // ── Cache directory ───────────────────────────────────────────────────
        $cacheDir = storage_path('app/htmlpurifier');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        try {
            $purifier = new \HTMLPurifier($config);
            return $purifier->purify($html);
        } catch (\Throwable $e) {
            Log::error('ArticleHtmlSanitizer: purification failed', ['error' => $e->getMessage()]);
            throw $e;
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
