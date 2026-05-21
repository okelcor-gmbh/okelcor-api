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

        // ── No file cache ─────────────────────────────────────────────────────
        // Avoids permission errors on production. Cost is negligible for article saves.
        $config->set('Cache.DefinitionImpl', null);

        // ── Custom definition ID (required to use maybeGetRawHTMLDefinition) ──
        $config->set('HTML.DefinitionID',  'okelcor-tiptap');
        $config->set('HTML.DefinitionRev', 2);

        // ── Allowed elements ──────────────────────────────────────────────────
        // ALL config directives must be set BEFORE maybeGetRawHTMLDefinition()
        // because that call finalizes the config internally.
        // HTML5 elements (mark, figure, figcaption) are registered via addElement
        // below — they must still appear here so the filter passes them through.
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
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // ── Link safety ───────────────────────────────────────────────────────
        $config->set('HTML.TargetBlank',    false);
        $config->set('HTML.TargetNoopener', true);

        // ── Allowed CSS in style attributes ───────────────────────────────────
        $config->set('CSS.AllowedProperties', ['text-align', 'vertical-align', 'width', 'min-width']);

        // ── Image safety ──────────────────────────────────────────────────────
        // Restrict img[src] to the app domain. Editors must use the body-image
        // upload endpoint. External a[href] links are NOT affected by this.
        $appDomain = parse_url(config('app.url'), PHP_URL_HOST) ?? '';
        if ($appDomain) {
            $config->set('URI.Host', $appDomain);
            $config->set('URI.DisableExternalResources', true);
        }

        $config->set('HTML.SafeIframe', false);

        // ── Register HTML5 elements and custom attributes ─────────────────────
        // HTMLPurifier's default definition is HTML 4.01. Elements like
        // mark/figure/figcaption and data-* attributes are not in that spec.
        // Without these registrations, HTMLPurifier calls trigger_error() for each
        // unknown element/attribute — Laravel converts those to ErrorException.
        //
        // IMPORTANT: this block must come AFTER all $config->set() calls because
        // maybeGetRawHTMLDefinition() finalizes the config; no set() after this.
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('mark',       'Inline', 'Inline', 'Common');
            $def->addElement('figure',     'Block',  'Flow',   'Common');
            $def->addElement('figcaption', 'Block',  'Flow',   'Common');

            // img[loading] is HTML5 — not in HTMLPurifier's default attribute list
            $def->addAttribute('img', 'loading', 'Text');

            // data-* on div for TipTap CTA / custom node blocks
            $def->addAttribute('div', 'data-type',      'Text');
            $def->addAttribute('div', 'data-cta-title', 'Text');
            $def->addAttribute('div', 'data-cta-text',  'Text');
            $def->addAttribute('div', 'data-cta-url',   'Text');
            $def->addAttribute('div', 'data-cta-label', 'Text');
        }

        try {
            $purifier = new \HTMLPurifier($config);
            return $purifier->purify($html);
        } catch (\Throwable $e) {
            Log::warning('ArticleHtmlSanitizer: HTMLPurifier failed', [
                'error'   => $e->getMessage(),
                'trigger' => get_class($e),
            ]);
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
