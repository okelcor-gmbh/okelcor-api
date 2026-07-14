<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sanitizes pasted-from-Outlook (or Word, or any webpage) rich HTML before
 * it is persisted — shared by the admin e-mail signature and the compose/
 * reply message body, since both are the same kind of input with the same
 * risk: stored XSS against our own staff UI, and a malformed/malicious paste
 * riding along into an outgoing e-mail.
 *
 * Pipeline (deliberately in this order, not sanitize-then-extract):
 *   1. Strip Word/Outlook namespace tags (<o:p>, <w:sdt>, <v:shape>, ...) —
 *      a generic HTML parser doesn't understand namespaces and will mangle
 *      them into ordinary tags that then look like legitimate content.
 *   2. Extract inline base64 images to real files, rewriting src to a public
 *      URL — done BEFORE the allow-list pass so the purifier never has to
 *      trust a data: URI at all (stricter than allowing the scheme through).
 *   3. Run the result through HTMLPurifier with a small allow-list suited to
 *      a signature/message body (not the full article editor's feature set).
 *
 * Sanitize once, at write time. Never re-sanitize on render — the stored
 * value is the boundary; rendering trusts it because this step already ran.
 */
class RichEmailHtmlSanitizer
{
    private const ALLOWED_IMAGE_TYPES = ['png', 'jpeg', 'jpg', 'gif', 'webp'];

    // A signature/pasted image this large is almost certainly a mistake
    // (e.g. a full-resolution photo), not a logo — reject rather than store.
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function sanitize(?string $html, string $imageStoragePrefix): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = $this->stripNamespaceTags($html);
        $html = $this->extractInlineImages($html, $imageStoragePrefix);

        return $this->purify($html);
    }

    /**
     * Outlook/Word export wraps content in namespaced tags a normal HTML
     * parser doesn't understand (e.g. <o:p>, <w:sdt>, <v:shape ...>).
     * Removing just the tags (keeping their inner text) before anything else
     * touches the string avoids them being silently reinterpreted as plain,
     * legitimate tags by a downstream parser.
     */
    private function stripNamespaceTags(string $html): string
    {
        return (string) preg_replace('#</?[a-zA-Z][a-zA-Z0-9]*:[a-zA-Z0-9-]+(?:\s[^>]*)?>#', '', $html);
    }

    /**
     * Finds <img src="data:image/...;base64,...">, decodes + verifies each
     * payload is a real image, writes it to public storage, and rewrites src
     * to the stored file's public URL. A payload that fails to decode or
     * doesn't verify as a real image is dropped (the <img> tag is removed)
     * rather than stored broken. Non-data-URI images (a plain https:// logo
     * URL, common in real signatures) are left untouched.
     */
    private function extractInlineImages(string $html, string $storagePrefix): string
    {
        if (! str_contains($html, 'data:image')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $images = iterator_to_array($dom->getElementsByTagName('img'));

        foreach ($images as $img) {
            $src = $img->getAttribute('src');

            if (! preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', trim($src), $m)) {
                continue; // not a data URI (e.g. an external https logo) — leave as-is
            }

            $ext     = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
            $decoded = base64_decode($m[2], true);

            $valid = $decoded !== false
                && strlen($decoded) <= self::MAX_IMAGE_BYTES
                && in_array($ext, self::ALLOWED_IMAGE_TYPES, true)
                && @getimagesizefromstring($decoded) !== false;

            if (! $valid) {
                $img->parentNode?->removeChild($img);
                continue;
            }

            try {
                $path = $storagePrefix . '/' . Str::random(24) . '.' . $ext;
                Storage::disk('public')->put($path, $decoded);
                $img->setAttribute('src', url('storage/' . $path));
            } catch (\Throwable $e) {
                Log::warning('RichEmailHtmlSanitizer: failed to store extracted image', [
                    'error' => $e->getMessage(),
                ]);
                $img->parentNode?->removeChild($img);
            }
        }

        // Serialize just the wrapper <div>'s inner content back out, not a
        // full document — this is a fragment, not a standalone HTML page.
        $wrapper = $dom->getElementsByTagName('div')->item(0);
        $out     = '';
        if ($wrapper) {
            foreach (iterator_to_array($wrapper->childNodes) as $child) {
                $out .= $dom->saveHTML($child);
            }
        }

        return $out !== '' ? $out : $html;
    }

    private function purify(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();

        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.DefinitionID', 'okelcor-rich-email');
        $config->set('HTML.DefinitionRev', 1);

        $config->set('HTML.Allowed',
            'a[href|title|target|rel],' .
            'b,strong,i,em,u,' .
            'span[style],' .
            'div[style],' .
            'p[style],br,hr,' .
            'img[src|alt|width|height|style],' .
            'table[style],tbody,thead,tr,' .
            'td[colspan|rowspan|style|align],th[colspan|rowspan|style|align],' .
            'ul,ol,li,' .
            'font[face|size|color],small,sub,sup'
        );

        // By the time this runs, extractInlineImages() has already rewritten
        // every data: URI to a real https URL — data is deliberately NOT in
        // this list, so nothing downstream ever has to trust one.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        $config->set('HTML.TargetBlank', false);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.SafeIframe', false);

        $config->set('CSS.AllowedProperties', [
            'color', 'background-color',
            'font-family', 'font-size', 'font-weight', 'font-style',
            'text-decoration', 'text-align', 'line-height', 'white-space',
            'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
            'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
            'border', 'border-collapse', 'vertical-align', 'width', 'height',
        ]);

        try {
            $purifier = new \HTMLPurifier($config);
            return $purifier->purify($html);
        } catch (\Throwable $e) {
            Log::warning('RichEmailHtmlSanitizer: HTMLPurifier failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Content could not be sanitized. Please check the content and try again.', 0, $e);
        }
    }
}
