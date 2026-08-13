<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns an InDesign "Publish Online / HTML5" export into campaign BLOCKS.
 *
 * The marketing team designs in InDesign because that is where they can make
 * something that actually looks good, then exports HTML. What comes out is not
 * an email and cannot be made into one by pasting it:
 *
 *   - the whole publication is an <iframe> inside a shell page with prev/next
 *     arrows, on a fixed 595 x 1089px canvas;
 *   - every element is `position:absolute` with a CSS `transform:translate()`;
 *   - every WORD is its own <span> at an exact `left:` offset inside a wrapper
 *     scaled by 0.05, so the type is really set at 420px and shrunk;
 *   - the fonts are @font-face TTFs shipped alongside.
 *
 * Outlook's rendering engine supports none of that — no absolute positioning,
 * no transforms, no webfonts — and Gmail strips most of it too. Sent as-is the
 * design does not degrade, it collapses into an unreadable pile of words.
 *
 * So this class does not embed the export. It RECOVERS it:
 *
 *   1. the copy, by grouping the positioned spans back into lines (same `top`,
 *      ordered by `left`) and lines back into paragraphs (one <p> = one
 *      paragraph, its several `top` values are just its visual line breaks);
 *   2. the real photographs, into the Media Library, so they get permanent
 *      absolute URLs and are reusable in any future campaign;
 *   3. the reading order, from each container's `transform:translate(x,y)` —
 *      document order in the export is InDesign's stacking order, not the
 *      order a person reads;
 *   4. the palette, from the generated stylesheet.
 *
 * The output is a normal block list, so it renders through CampaignBlockRenderer
 * exactly like a hand-built campaign: table-based, inline-styled, Outlook-safe,
 * with the merge tags and the unsubscribe footer the send job depends on.
 *
 * WHAT THIS DOES NOT DO, and cannot: reproduce the InDesign layout pixel for
 * pixel. Overlapping text on a full-bleed photo, exact leading and letter
 * spacing, the display typeface — none of that survives in email, by any method.
 * What survives is the imagery, the words, the order and the colours. The
 * import is deliberately a STARTING POINT the marketer then edits in the
 * campaign editor, which is also why a mis-grouped list or a heading read as a
 * paragraph is a five-second fix rather than a failed import.
 */
class InDesignEmailImporter
{
    /** Hard ceilings on the archive, checked before anything is written to disk. */
    private const MAX_ENTRIES        = 400;
    private const MAX_UNCOMPRESSED   = 120 * 1024 * 1024;
    private const MAX_IMAGES         = 40;

    /**
     * Images smaller than this on their long side are InDesign furniture —
     * rules, bullet glyphs, the 1px gradient strips it emits as PNGs — not
     * content. Importing them would fill the Media Library with slivers and put
     * meaningless full-width bars in the email.
     */
    private const MIN_IMAGE_LONG_SIDE  = 200;
    private const MIN_IMAGE_SHORT_SIDE = 40;

    /** A run of at least this many consecutive short paragraphs reads as a list. */
    private const LIST_MIN_ITEMS  = 3;
    private const LIST_MAX_LENGTH = 140;

    /**
     * A long, thin graphic is a rule — InDesign draws the gold hairlines under
     * its headings as PNGs. Rendered as an email image it becomes a full-width
     * bar; as a divider block it is the thing it was drawn to be. Both bounds
     * are needed: the ratio alone would catch a legitimate wide banner.
     */
    private const RULE_MIN_RATIO      = 6.0;
    private const RULE_MAX_SHORT_SIDE = 80;

    /**
     * A rule of one flat colour, with a heading sitting on it, is not a rule at
     * all — it is the coloured band behind a section title. Sampled at the
     * centre and confirmed flat, so a photograph that happens to be band-shaped
     * is not mistaken for one.
     */
    private const BAND_FLATNESS_SAMPLES = 24;

    /** How far apart two images' tops may be and still read as one row. */
    private const ROW_Y_TOLERANCE = 24.0;

    /**
     * A banner: a picture the words are printed ON, rather than one they sit
     * under. Both bounds are needed. The page share alone would catch a wide
     * mid-page photograph with a caption crossing its corner; the ratio alone
     * would catch a small inset picture with a label on it. A masthead is both
     * — most of the page across, and markedly wider than it is tall.
     */
    private const HERO_MIN_PAGE_SHARE = 0.6;
    private const HERO_MIN_RATIO      = 1.8;

    /**
     * A headline and a standfirst, at most. Beyond that the picture is not a
     * banner but a page background, and lifting a whole article onto it would
     * bury the copy in artwork.
     */
    private const HERO_MAX_TEXTS = 4;

    /** Only these ever leave the archive. Fonts and scripts are useless in email. */
    private const ALLOWED_EXTENSIONS = ['html', 'htm', 'xhtml', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const IMPORTABLE_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** The InDesign page width, used to tell a full-bleed band from an inset one. */
    private float $pageWidth = 595.0;

    public function __construct(private MediaLibraryService $mediaLibrary)
    {
    }

    /**
     * @return array{
     *     blocks: array<int, array<string, mixed>>,
     *     theme: array<string, mixed>,
     *     media: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     source: array<string, mixed>
     * }
     *
     * @throws RuntimeException when the archive is not a usable InDesign export
     */
    public function import(UploadedFile $zip, ?int $uploadedBy = null, string $collection = 'campaigns'): array
    {
        $workspace = $this->extract($zip);

        try {
            return $this->convert($workspace, $uploadedBy, $collection);
        } finally {
            // The extracted copy is scratch. The images that matter are already
            // in the Media Library on the public disk by this point.
            File::deleteDirectory($workspace);
        }
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    /**
     * Unpacks the archive into a fresh temporary directory.
     *
     * An admin uploading a ZIP is still an untrusted archive: this rejects
     * path traversal ("zip slip") and refuses an archive that would decompress
     * to an absurd size, rather than trusting that a marketer's export is
     * well-formed.
     */
    private function extract(UploadedFile $zip): string
    {
        $archive = new \ZipArchive();

        if ($archive->open($zip->getRealPath()) !== true) {
            throw new RuntimeException('That file could not be opened as a ZIP archive.');
        }

        if ($archive->numFiles > self::MAX_ENTRIES) {
            $archive->close();
            throw new RuntimeException('That archive contains too many files to be an InDesign export.');
        }

        $workspace = storage_path('app/indesign-import/' . Str::uuid());
        File::ensureDirectoryExists($workspace);

        $total = 0;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];

            // Directories are recreated implicitly by the files inside them.
            if (str_ends_with($name, '/')) {
                continue;
            }

            // macOS zips carry a __MACOSX shadow tree of AppleDouble files.
            if (str_starts_with($name, '__MACOSX/') || str_contains($name, '/._') || basename($name) === '.DS_Store') {
                continue;
            }

            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $total += (int) $stat['size'];

            if ($total > self::MAX_UNCOMPRESSED) {
                $archive->close();
                File::deleteDirectory($workspace);
                throw new RuntimeException('That archive is too large once unpacked.');
            }

            // Zip slip: resolve the destination against the workspace and refuse
            // anything that climbs out of it. Checked on the concatenated path
            // BEFORE creating any directory, so a traversal never causes a write.
            $destination = $this->resolveInside($workspace, $name);

            if ($destination === null) {
                $archive->close();
                File::deleteDirectory($workspace);
                throw new RuntimeException('That archive contains an unsafe file path.');
            }

            $contents = $archive->getFromIndex($i);

            if ($contents === false) {
                continue;
            }

            File::ensureDirectoryExists(dirname($destination));
            file_put_contents($destination, $contents);
        }

        $archive->close();

        return $workspace;
    }

    /**
     * Returns the absolute destination for an archive entry, or null if it would
     * land outside the workspace. Purely lexical — the file does not exist yet,
     * so realpath() is not available on the destination itself.
     */
    private function resolveInside(string $workspace, string $entry): ?string
    {
        $entry = str_replace('\\', '/', $entry);

        if (str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Refuse rather than pop: an entry that needs to climb at all is
                // not something a legitimate export produces.
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : $workspace . '/' . implode('/', $segments);
    }

    // -------------------------------------------------------------------------
    // Conversion
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function convert(string $workspace, ?int $uploadedBy, string $collection): array
    {
        $documentPath = $this->locateDocument($workspace);

        if ($documentPath === null) {
            throw new RuntimeException('No InDesign page could be found in that archive. Export from InDesign with File → Publish Online (HTML), and upload the whole exported folder zipped.');
        }

        $css        = $this->loadStylesheets($documentPath);
        $containers = $this->parseContainerPositions($css);
        $charStyles = $this->parseCharacterStyles($css);

        $document = $this->loadHtml(file_get_contents($documentPath));

        $warnings = [];
        $items    = [];

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body instanceof \DOMElement && preg_match('/(?<![a-z-])width\s*:\s*([\d.]+)px/i', $body->getAttribute('style'), $m)) {
            $this->pageWidth = max(1.0, (float) $m[1]);
        }

        if ($body instanceof \DOMElement) {
            $this->collect($body, $containers, $charStyles, dirname($documentPath), 0.0, 1.0, $items, $warnings);
        }

        if ($items === []) {
            throw new RuntimeException('That export contained no text or images this could read. If it was produced by something other than InDesign, build the campaign in the editor instead.');
        }

        // Reading order, not stacking order. InDesign emits containers in the
        // order the designer created them; `y` is where they actually sit.
        usort($items, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        $rules  = [];
        $media  = $this->importImages($items, $uploadedBy, $collection, $warnings, $rules);
        $theme  = $this->deriveTheme($items, $css, $warnings, $this->bands($items, $rules)['colour']);
        // A banner's height has to be expressed against the card it will be
        // rendered into, and which card that is was decided by the theme a line
        // above — a FET deck lands on a 680px card, the house preset on 620.
        $blocks = $this->buildBlocks($items, $media, $rules, $warnings, $this->cardWidthFor($theme));

        return [
            'blocks'   => $blocks,
            'theme'    => $theme,
            'media'    => array_values($media),
            'warnings' => $warnings,
            'source'   => [
                'document'    => basename($documentPath),
                'text_frames' => count(array_filter($items, fn ($i) => $i['kind'] === 'text')),
                'images_seen' => count(array_filter($items, fn ($i) => $i['kind'] === 'image')),
            ],
        ];
    }

    /**
     * Finds the page document. `index.html` at the root of an InDesign export is
     * the iframe shell — navigation arrows and nothing else — so the real page
     * is whichever HTML file carries the content.
     */
    private function locateDocument(string $workspace): ?string
    {
        $candidates = [];

        foreach (File::allFiles($workspace) as $file) {
            if (! in_array(strtolower($file->getExtension()), ['html', 'htm', 'xhtml'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // The shell is recognisable, and small: it points at the page via an
            // iframe and holds no content of its own.
            if (str_contains($contents, '<iframe') && ! str_contains($contents, '_idContainer')) {
                continue;
            }

            $candidates[$file->getPathname()] = substr_count($contents, '_idContainer') * 1000 + strlen($contents);
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);

        return array_key_first($candidates);
    }

    /** Concatenates every stylesheet the document links, plus any inline <style>. */
    private function loadStylesheets(string $documentPath): string
    {
        $html = file_get_contents($documentPath);
        $dir  = dirname($documentPath);
        $css  = '';

        if (preg_match_all('/<link[^>]+href=["\']([^"\']+\.css)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $path = realpath($dir . '/' . $href);

                if ($path !== false && is_file($path)) {
                    $css .= file_get_contents($path) . "\n";
                }
            }
        }

        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            $css .= implode("\n", $matches[1]);
        }

        return $css;
    }

    /**
     * `#_idContainer007 { transform: translate(199.098px, 108.083px) ... }` —
     * where each container actually sits on the page.
     *
     * @return array<string, array{x: float, y: float}>
     */
    private function parseContainerPositions(string $css): array
    {
        $positions = [];

        if (! preg_match_all('/#(_idContainer\d+)\s*\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER)) {
            return $positions;
        }

        foreach ($matches as $match) {
            [$x, $y] = $this->readTranslate($match[2]);

            $positions[$match[1]] = [
                'x'      => $x,
                'y'      => $y,
                // The box matters as well as the corner: a band's width is what
                // says whether it runs the full page or is an inset pill.
                'width'  => preg_match('/(?<![a-z-])width\s*:\s*([\d.]+)px/i', $match[2], $m) ? (float) $m[1] : 0.0,
                'height' => preg_match('/(?<![a-z-])height\s*:\s*([\d.]+)px/i', $match[2], $m) ? (float) $m[1] : 0.0,
            ];
        }

        return $positions;
    }

    /**
     * `span.CharOverride-3 { font-size:260px; font-weight:bold; ... }` — the
     * typographic weight of each run, which is what separates a heading from a
     * paragraph once the layout is gone.
     *
     * @return array<string, array{size: float, bold: bool, color: ?string, upper: bool}>
     */
    private function parseCharacterStyles(string $css): array
    {
        $styles = [];

        if (! preg_match_all('/span\.(CharOverride-\d+)\s*\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER)) {
            return $styles;
        }

        foreach ($matches as $match) {
            $declarations = [];

            if (preg_match_all('/([a-z-]+)\s*:\s*([^;]+);/i', $match[2], $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pair) {
                    $declarations[strtolower(trim($pair[1]))] = trim($pair[2]);
                }
            }

            $weight = strtolower($declarations['font-weight'] ?? 'normal');

            $styles[$match[1]] = [
                'size'  => (float) ($declarations['font-size'] ?? 0),
                'bold'  => $weight === 'bold' || (is_numeric($weight) && (int) $weight >= 600),
                'color' => $this->normaliseColour($declarations['color'] ?? null),
                'upper' => strtolower($declarations['text-transform'] ?? '') === 'uppercase',
            ];
        }

        return $styles;
    }

    /**
     * Walks the tree accumulating each element's translate/scale, so a nested
     * container's position is absolute on the page rather than relative to a
     * parent this output no longer has.
     *
     * @param  array<string, array{x: float, y: float}>  $containers
     * @param  array<string, array{size: float, bold: bool, color: ?string, upper: bool}>  $charStyles
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $warnings
     */
    private function collect(
        \DOMElement $element,
        array $containers,
        array $charStyles,
        string $baseDir,
        float $offsetY,
        float $scale,
        array &$items,
        array &$warnings,
        float $offsetX = 0.0,
        array $box = ['width' => 0.0, 'height' => 0.0]
    ): void {
        foreach ($element->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $x = $offsetX;
            $y = $offsetY;
            $s = $scale;
            $b = $box;

            $id = $child->getAttribute('id');

            if ($id !== '' && isset($containers[$id])) {
                $x += $containers[$id]['x'];
                $y += $containers[$id]['y'];

                // Remembered for any <img> inside: the picture fills its frame,
                // so the frame's box is the picture's box on the page.
                $b = ['width' => $containers[$id]['width'], 'height' => $containers[$id]['height']];
            }

            $style = $child->getAttribute('style');

            if ($style !== '') {
                [$dx, $dy] = $this->readTranslate($style);
                $x += $dx;
                $y += $dy;

                if (preg_match('/scale\(\s*([\d.]+)/', $style, $m)) {
                    $s *= (float) $m[1];
                }

                // A frame can also be placed with plain left/top.
                if (preg_match('/(?<![a-z-])left\s*:\s*(-?[\d.]+)px/i', $style, $m)) {
                    $x += (float) $m[1];
                }
                if (preg_match('/(?<![a-z-])top\s*:\s*(-?[\d.]+)px/i', $style, $m)) {
                    $y += (float) $m[1];
                }
            }

            if (strtolower($child->tagName) === 'img') {
                $items[] = [
                    'kind'   => 'image',
                    'x'      => $x,
                    'y'      => $y,
                    'width'  => $b['width'],
                    'height' => $b['height'],
                    'src'    => $child->getAttribute('src'),
                    'alt'    => $child->getAttribute('alt'),
                    'path'   => $baseDir,
                ];

                continue;
            }

            if (strtolower($child->tagName) === 'p') {
                $paragraph = $this->readParagraph($child, $charStyles, $s);

                if ($paragraph !== null) {
                    // The frame's box travels with the paragraph, not just its
                    // corner. Where a headline sits ACROSS a banner — left,
                    // centred, right — is a fact about the middle of its frame,
                    // and the corner alone cannot tell centred from left.
                    $items[] = $paragraph + ['x' => $x, 'y' => $y + $paragraph['offset'], 'box' => $b];
                }

                continue;
            }

            $this->collect($child, $containers, $charStyles, $baseDir, $y, $s, $items, $warnings, $x, $b);
        }
    }

    /**
     * Reassembles one paragraph.
     *
     * Each word is its own absolutely-positioned span. Spans sharing a `top` are
     * one visual line; ordered by `left` they are that line's words. The lines
     * are then joined with a space, because a line break inside a paragraph is
     * InDesign's justification, not the author's intent — hard-wrapping it into
     * the email would produce ragged text at every other width.
     *
     * @param  array<string, array{size: float, bold: bool, color: ?string, upper: bool}>  $charStyles
     * @return array<string, mixed>|null
     */
    private function readParagraph(\DOMElement $paragraph, array $charStyles, float $scale): ?array
    {
        $lines   = [];
        $weights = [];
        $first   = null;

        foreach ($paragraph->getElementsByTagName('span') as $span) {
            $text = $span->textContent;

            if ($text === '') {
                continue;
            }

            $style = $span->getAttribute('style');

            $top  = preg_match('/(?<![a-z-])top\s*:\s*(-?[\d.]+)px/i', $style, $m) ? (float) $m[1] : 0.0;
            $left = preg_match('/(?<![a-z-])left\s*:\s*(-?[\d.]+)px/i', $style, $m) ? (float) $m[1] : 0.0;

            // Round the line key: InDesign emits sub-pixel `top` values that are
            // visually one line but differ in the third decimal.
            $key = (string) round($top, 1);

            $bold = false;

            foreach (preg_split('/\s+/', $span->getAttribute('class')) ?: [] as $class) {
                if (($charStyles[$class]['bold'] ?? false) === true) {
                    $bold = true;
                }
            }

            $lines[$key][] = ['left' => $left, 'text' => $text, 'bold' => $bold];

            $first ??= $top;

            // Attribute the paragraph's weight to whichever character style
            // covers the most of it, so one stray styled word cannot promote a
            // paragraph to a heading.
            foreach (preg_split('/\s+/', $span->getAttribute('class')) ?: [] as $class) {
                if (isset($charStyles[$class])) {
                    $weights[$class] = ($weights[$class] ?? 0) + mb_strlen($text);
                }
            }
        }

        if ($lines === []) {
            // A paragraph can hold bare text with no spans.
            $text = $this->tidy($paragraph->textContent);

            return $text === '' ? null : [
                'kind'   => 'text',
                'text'   => $text,
                'size'   => 0.0,
                'bold'   => false,
                'color'  => null,
                'upper'  => false,
                'offset' => 0.0,
            ];
        }

        uksort($lines, fn ($a, $b) => (float) $a <=> (float) $b);

        $assembled = [];

        arsort($weights);
        $dominant = array_key_first($weights);
        $style    = $dominant !== null ? $charStyles[$dominant] : ['size' => 0.0, 'bold' => false, 'color' => null, 'upper' => false];

        foreach ($lines as $line) {
            usort($line, fn ($a, $b) => $a['left'] <=> $b['left']);

            // Bold runs inside a paragraph — "**Fuel Eco Tech (FET)** offers…",
            // "**Marine** – Boats, ships…" — are real emphasis the designer
            // applied, and taking only the paragraph's dominant style threw
            // them away. Re-expressed in the inline syntax the renderer already
            // understands, which escapes everything and then reintroduces only
            // the markup it generates itself.
            $assembled[] = $this->tidy($this->emphasise($line, (bool) $style['bold']));
        }

        $text = $this->tidy(implode(' ', array_filter($assembled, fn ($l) => $l !== '')));

        if ($text === '') {
            return null;
        }

        return [
            'kind'  => 'text',
            'text'  => $text,
            // The declared size is pre-scale: the wrapper shrinks the whole
            // frame, so 420px at scale 0.05 is 21px on the page.
            'size'  => $style['size'] * $scale,
            'bold'  => $style['bold'],
            'color' => $style['color'],
            'upper' => $style['upper'],
            // Sort by where the paragraph starts, not where its frame does —
            // one frame can hold a dozen paragraphs.
            'offset' => ($first ?? 0.0) * $scale,
        ];
    }

    /**
     * Joins one visual line's spans, wrapping runs that are bolder than the
     * paragraph in `**`.
     *
     * Only marks a run when it differs from the paragraph's own weight — a
     * heading set entirely in bold is already bold, and wrapping all of it
     * would put literal asterisks in front of a marketer who never typed one.
     *
     * @param  array<int, array{left: float, text: string, bold: bool}>  $line
     */
    private function emphasise(array $line, bool $paragraphIsBold): string
    {
        // Merge neighbouring spans of the same weight first. Each word is its
        // own span, so without this a five-word bold run would be wrapped five
        // times over.
        $runs = [];

        foreach ($line as $span) {
            $bold = $span['bold'] && ! $paragraphIsBold;
            // An asterisk in the source would otherwise pair with the ones added
            // here and swallow the text between them.
            $text = str_replace('*', '', $span['text']);

            if ($runs !== [] && $runs[count($runs) - 1]['bold'] === $bold) {
                $runs[count($runs) - 1]['text'] .= $text;
                continue;
            }

            $runs[] = ['bold' => $bold, 'text' => $text];
        }

        $out = '';

        foreach ($runs as $run) {
            if (! $run['bold'] || trim($run['text']) === '') {
                $out .= $run['text'];
                continue;
            }

            // The spaces around a run belong OUTSIDE its markers. InDesign puts
            // the trailing space inside the styled span, so marking the span
            // verbatim yields "of** 15%–35%**" — which renders as a literal
            // asterisk pair, because the syntax needs the marker against the
            // word.
            preg_match('/^(\s*)(.*?)(\s*)$/su', $run['text'], $m);

            $out .= $m[1] . '**' . $m[2] . '**' . $m[3];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Images
    // -------------------------------------------------------------------------

    /**
     * Registers the real photographs in the Media Library and drops InDesign's
     * furniture.
     *
     * Going through MediaLibraryService rather than storing files directly is
     * the whole "reuse" half of this: the pictures land in the same library the
     * campaign editor already browses, so the next campaign can pick them up
     * without another import.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $warnings
     * @param  array<string, ?string>  $rules  srcs that are hairlines, mapped to
     *                                         their fill colour when they are a
     *                                         flat band rather than a rule
     * @return array<string, array<string, mixed>>  keyed by the src it came from
     */
    private function importImages(array $items, ?int $uploadedBy, string $collection, array &$warnings, array &$rules): array
    {
        $imported = [];
        $skipped  = 0;

        foreach ($items as $item) {
            if ($item['kind'] !== 'image') {
                continue;
            }

            $src = $item['src'];

            if ($src === '' || isset($imported[$src])) {
                continue;
            }

            if (count($imported) >= self::MAX_IMAGES) {
                $warnings[] = 'Only the first ' . self::MAX_IMAGES . ' images were imported.';
                break;
            }

            [$binary, $filename] = $this->readImage($src, $item['path'] ?? null);

            if ($binary === null) {
                continue;
            }

            $size = @getimagesizefromstring($binary);

            if ($size === false) {
                continue;
            }

            [$width, $height] = $size;

            $long  = max($width, $height);
            $short = max(1, min($width, $height));

            // Bullet glyphs and gradient slivers — real in the print layout,
            // meaningless as a standalone email image.
            if ($long < self::MIN_IMAGE_LONG_SIDE || $short < self::MIN_IMAGE_SHORT_SIDE) {
                $skipped++;
                continue;
            }

            // A hairline under a heading. Recorded, not imported: it comes back
            // as a divider block, which is what it was drawn to be — unless it
            // is one flat colour, in which case it is the band behind a section
            // title and its colour is worth keeping.
            if ($long / $short >= self::RULE_MIN_RATIO && $short <= self::RULE_MAX_SHORT_SIDE) {
                $rules[$src] = $this->flatColour($binary);
                continue;
            }

            $temporary = tempnam(sys_get_temp_dir(), 'idml');
            file_put_contents($temporary, $binary);

            try {
                $upload = new UploadedFile(
                    $temporary,
                    $filename,
                    $size['mime'] ?? 'image/png',
                    null,
                    true
                );

                $media = $this->mediaLibrary->store($upload, $collection, null, $uploadedBy);

                $imported[$src] = [
                    'media_id' => $media->id,
                    'url'      => $media->url,
                    'width'    => $media->width,
                    'height'   => $media->height,
                    'filename' => $media->original_name,
                ];
            } catch (\Throwable $e) {
                $warnings[] = 'One image could not be imported (' . $filename . ').';
            } finally {
                @unlink($temporary);
            }
        }

        if ($skipped > 0) {
            $warnings[] = $skipped . ' decorative graphic' . ($skipped === 1 ? '' : 's')
                . ' (rules, bullet marks) were left out — they only mean something in the print layout.';
        }

        return $imported;
    }

    /**
     * The graphic's colour if it is one flat colour, null if it has any detail.
     *
     * This is what separates the gold hairline under a heading (a rule) from
     * the green band behind one (a section header). Both are long thin PNGs in
     * the export and nothing else distinguishes them.
     */
    private function flatColour(string $binary): ?string
    {
        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $first  = null;

        for ($i = 0; $i < self::BAND_FLATNESS_SAMPLES; $i++) {
            $x = (int) round(($width - 1) * $i / max(1, self::BAND_FLATNESS_SAMPLES - 1));
            $y = intdiv($height, 2);

            $rgb = imagecolorat($image, $x, $y) & 0xFFFFFF;

            if ($first === null) {
                $first = $rgb;
                continue;
            }

            // Any variation at all and this is artwork, not a fill.
            if ($rgb !== $first) {
                imagedestroy($image);

                return null;
            }
        }

        imagedestroy($image);

        if ($first === null) {
            return null;
        }

        $hex = sprintf('#%06X', $first);

        // A white or near-black fill is a spacer or a shadow, not a band worth
        // colouring a section header with.
        return in_array($hex, ['#FFFFFF', '#000000'], true) ? null : $hex;
    }

    /**
     * @return array{0: ?string, 1: string}  raw bytes and a filename
     */
    private function readImage(string $src, ?string $baseDir): array
    {
        if (str_starts_with($src, 'data:')) {
            if (! preg_match('#^data:image/([a-z0-9.+-]+);base64,(.*)$#is', $src, $m)) {
                return [null, ''];
            }

            $binary = base64_decode($m[2], true);

            return [$binary === false ? null : $binary, 'embedded.' . strtolower($m[1])];
        }

        // Remote references are not fetched: an import must not turn into an
        // outbound request to an address inside the archive.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $src) || str_starts_with($src, '//')) {
            return [null, ''];
        }

        if ($baseDir === null) {
            return [null, ''];
        }

        $path = realpath($baseDir . '/' . rawurldecode($src));

        if ($path === false || ! is_file($path)) {
            return [null, ''];
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMPORTABLE_IMAGE_EXTENSIONS, true)) {
            return [null, ''];
        }

        return [file_get_contents($path), basename($path)];
    }

    // -------------------------------------------------------------------------
    // Theme
    // -------------------------------------------------------------------------

    /**
     * Derives the palette from the export, then checks it is actually legible.
     *
     * The check matters more than the derivation. InDesign sets this deck's type
     * in white because it sits on a full-bleed dark photograph — a background
     * this import cannot carry across. Taking the colours at face value would
     * produce white text on the white page colour: an email that arrives blank.
     * When the recovered pair does not have enough contrast, the house preset
     * wins and the marketer is told why.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function deriveTheme(array $items, string $css, array &$warnings, ?string $bandColour = null): array
    {
        $byColour = [];

        foreach ($items as $item) {
            if ($item['kind'] !== 'text' || $item['color'] === null) {
                continue;
            }

            $byColour[$item['color']] = ($byColour[$item['color']] ?? 0) + mb_strlen($item['text']);
        }

        arsort($byColour);

        $text   = array_key_first($byColour);
        $accent = $this->accentColour($byColour, $text);
        $page   = $this->pageColour($css);

        // A band colour is the strongest signal in the file about what the deck
        // is meant to look like: it is a deliberate fill the designer chose,
        // not type that happens to sit on artwork. Where a matching preset
        // exists, start from it rather than reconstructing the palette one key
        // at a time — that is what a preset is for, and it means the NEXT
        // campaign for the same product starts correct too.
        $theme = ['preset' => $this->presetFor($bandColour)];

        if ($bandColour !== null) {
            $theme['band_background'] = $bandColour;
            $theme['band_text_color'] = $this->luminance($bandColour) > 0.5 ? '#111111' : '#FFFFFF';
        }

        if ($text === null || $page === null) {
            $warnings[] = 'No usable colours were found in the export, so the house theme was applied.';

            return $theme;
        }

        if ($this->contrast($text, $page) < 4.5) {
            // The commonest real case, and the one worth naming: light type that
            // only worked because it sat on a photograph.
            $warnings[] = 'The design sets its type in ' . $text . ' against ' . $page
                . ', which is unreadable once the background artwork is gone. The house theme was applied instead — change it in the editor if you want different colours.';

            return $theme;
        }

        $theme['background']      = $page;
        $theme['card_background'] = $page;
        $theme['text_color']      = $text;
        $theme['heading_color']   = $accent ?? $text;

        if ($accent !== null) {
            $theme['button_background'] = $accent;
            $theme['button_text_color'] = $this->luminance($accent) > 0.5 ? '#000000' : '#FFFFFF';
        }

        return $theme;
    }

    /**
     * Picks the preset whose own accent is closest to the design's band colour,
     * falling back to the house default when nothing is near enough.
     *
     * Matching on the band rather than asking the marketer to choose means a
     * Fuel Eco Tech deck lands on the FET preset by itself — including its card
     * surface and dark tone, which no amount of per-campaign colour overriding
     * would have produced.
     */
    private function presetFor(?string $bandColour): string
    {
        if ($bandColour === null) {
            return CampaignBlockRenderer::DEFAULT_THEME;
        }

        $best     = CampaignBlockRenderer::DEFAULT_THEME;
        $distance = INF;

        foreach (CampaignBlockRenderer::THEMES as $preset => $values) {
            $candidate = $this->distance($bandColour, $values['band_background']);

            if ($candidate < $distance) {
                $distance = $candidate;
                $best     = $preset;
            }
        }

        // Roughly "the same colour to the eye". Beyond it the design is using
        // something the presets do not cover, and the per-campaign band
        // override above carries the colour instead.
        return $distance <= 60.0 ? $best : CampaignBlockRenderer::DEFAULT_THEME;
    }

    /** Straight-line distance in RGB. Crude, but only ever used to rank presets. */
    private function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = $this->rgb($a);
        [$r2, $g2, $b2] = $this->rgb($b);

        return sqrt((($r1 - $r2) ** 2) + (($g1 - $g2) ** 2) + (($b1 - $b2) ** 2));
    }

    /**
     * The colour used for emphasis rather than body copy — least text, but
     * present. In this deck that is the gold the display heading is set in.
     *
     * @param  array<string, int>  $byColour
     */
    private function accentColour(array $byColour, ?string $text): ?string
    {
        foreach (array_reverse(array_keys($byColour)) as $colour) {
            if ($colour !== $text) {
                return $colour;
            }
        }

        return null;
    }

    private function pageColour(string $css): ?string
    {
        if (preg_match('/background-color\s*:\s*(#[0-9a-f]{3,8}|white|black)/i', $css, $m)) {
            return $this->normaliseColour($m[1]);
        }

        return '#FFFFFF';
    }

    /** WCAG relative-contrast ratio, used only to decide legible vs not. */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);

        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            (float) hexdec(substr($hex, 0, 2)),
            (float) hexdec(substr($hex, 2, 2)),
            (float) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function normaliseColour(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        $named = ['white' => '#FFFFFF', 'black' => '#000000'];

        if (isset($named[$value])) {
            return $named[$value];
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
            return strtoupper($value);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Blocks
    // -------------------------------------------------------------------------

    /**
     * Turns the ordered items into blocks.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $media
     * @param  array<string, true>  $rules
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(array $items, array $media, array $rules, array &$warnings, int $cardWidth = 620): array
    {
        $body      = $this->bodySize($items);
        $headings  = $this->headingSizes($items, $body);
        $blocks    = [];
        $paragraph = [];

        $flush = function () use (&$paragraph, &$blocks): void {
            if ($paragraph === []) {
                return;
            }

            // A run of short paragraphs is a bulleted list in the original.
            // InDesign draws the bullet marks as separate images, so length is
            // the only thing left to recognise them by.
            //
            // The run is scanned for stretches of short paragraphs rather than
            // being judged as a whole: a list is usually introduced by a
            // sentence longer than its items ("…is designed for a wide range of
            // engines:"), and testing the whole run would let that one sentence
            // turn ten bullets back into ten loose paragraphs.
            $pending = [];

            $emit = function () use (&$pending, &$blocks): void {
                if ($pending === []) {
                    return;
                }

                if (count($pending) >= self::LIST_MIN_ITEMS) {
                    $blocks[] = ['type' => 'list', 'items' => array_values($pending)];
                } else {
                    foreach ($pending as $text) {
                        $blocks[] = ['type' => 'text', 'text' => $text, 'align' => 'left'];
                    }
                }

                $pending = [];
            };

            foreach ($paragraph as $text) {
                if (mb_strlen($text) <= self::LIST_MAX_LENGTH) {
                    $pending[] = $text;
                    continue;
                }

                $emit();
                $blocks[] = ['type' => 'text', 'text' => $text, 'align' => 'left'];
            }

            $emit();

            $paragraph = [];
        };

        $bands  = $this->bands($items, $rules);
        $heroes = $this->heroes($items, $media, $cardWidth);
        $rows   = $this->imageRows($items, $media, $heroes['byImage']);

        // The banner's own words start out claimed, so they cannot ALSO be
        // emitted as a loose heading under the picture — which is exactly what
        // was reported.
        $taken = $heroes['consumed'];

        foreach ($items as $index => $item) {
            if (isset($taken[$index])) {
                continue;
            }

            if ($item['kind'] === 'image') {
                if (array_key_exists($item['src'], $rules)) {
                    // A band is rendered by the heading that sits on it, not on
                    // its own — an empty coloured bar says nothing.
                    if (in_array($index, $bands['consumed'], true)) {
                        continue;
                    }

                    $flush();

                    // The same hairline art is reused throughout the deck, and
                    // InDesign stacks several to draw one line — collapse them,
                    // and never open the email on one.
                    if ($blocks !== [] && end($blocks)['type'] !== 'divider') {
                        $blocks[] = ['type' => 'divider'];
                    }

                    continue;
                }

                if (! isset($media[$item['src']])) {
                    continue;
                }

                $flush();

                // A banner carries its own headline, so it is emitted here
                // rather than as a picture followed by a stray heading.
                if (isset($heroes['byImage'][$index])) {
                    $blocks[] = $heroes['byImage'][$index];

                    continue;
                }

                // Images sitting beside each other on the page, not under each
                // other. Rendering them as separate blocks stacks them, which
                // is the whole reported complaint.
                if (isset($rows[$index])) {
                    $block = ['type' => 'image_row'];

                    foreach (array_values($rows[$index]) as $slot => $member) {
                        $taken[$member] = true;
                        $block['image_' . ($slot + 1)] = $media[$items[$member]['src']]['url'];
                        $block['alt_' . ($slot + 1)]   = $this->tidy((string) $items[$member]['alt']);
                    }

                    $blocks[] = $block;

                    continue;
                }

                $blocks[] = [
                    'type' => 'image',
                    'url'  => $media[$item['src']]['url'],
                    'alt'  => $this->tidy((string) $item['alt']),
                ];

                continue;
            }

            // A heading sitting on a coloured band is a section header. Emitted
            // as a plain heading it loses the band, and the band was most of
            // what made the design read as designed.
            if (isset($bands['byItem'][$index])) {
                $flush();

                $blocks[] = [
                    'type'  => 'section_header',
                    'text'  => $item['text'],
                    'style' => $bands['byItem'][$index]['style'],
                    'tone'  => 'accent',
                ];

                continue;
            }

            $level = $this->headingLevel($item, $body, $headings);

            if ($level !== null) {
                $flush();

                $blocks[] = [
                    'type'  => 'heading',
                    'text'  => $item['text'],
                    'level' => $level,
                    'align' => 'center',
                ];

                continue;
            }

            $paragraph[] = $item['text'];
        }

        $flush();

        if ($blocks === []) {
            throw new RuntimeException('Nothing in that export could be turned into email content.');
        }

        // The team's own sign-off, and the unsubscribe line the send job needs
        // is added by the renderer regardless.
        $blocks[] = [
            'type'          => 'footer',
            'address_lines' => ['Okelcor'],
            'site_label'    => 'Visit okelcor.com',
            'site_url'      => rtrim(config('app.frontend_url', 'https://okelcor.com'), '/'),
        ];

        if (! array_filter($blocks, fn ($b) => $b['type'] === 'button')) {
            $warnings[] = 'The design has no call-to-action button — InDesign exports carry no links. Add one in the editor before sending.';
        }

        if ($heroes['byImage'] !== []) {
            $warnings[] = 'The banner headline was placed ON the picture rather than under it, in the position it occupied in the design. '
                . 'If it falls over the wrong part of the artwork, move it with the position control on that block — '
                . 'and remember mail read with images turned off shows the text on a plain colour, so keep it short.';
        }

        return $blocks;
    }

    /**
     * The card width the chosen theme will render into, which is what a
     * banner's height has to be expressed against.
     *
     * @param  array<string, mixed>  $theme
     */
    private function cardWidthFor(array $theme): int
    {
        $preset = (string) ($theme['preset'] ?? CampaignBlockRenderer::DEFAULT_THEME);

        return (int) (CampaignBlockRenderer::THEMES[$preset]['card_width']
            ?? CampaignBlockRenderer::THEMES[CampaignBlockRenderer::DEFAULT_THEME]['card_width']);
    }

    /**
     * Matches each banner to the words printed on it.
     *
     * The reported defect, and the shape of it: the masthead's headline and
     * standfirst arrived UNDER the picture. They were never under it. In the
     * export the picture is one frame and each line of type is another, and the
     * type frames sit inside the picture's box — the relationship is spatial and
     * nothing read it, so the only ordering left was top-to-bottom by `y`, which
     * puts a picture starting at y=-3 above a headline starting at y=75.
     *
     * The other half of the fix is that there was nowhere to put them: until the
     * `hero` block existed, no block held text and an image in the same space,
     * so even a correct reading had to emit them one after the other.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $media
     * @return array{byImage: array<int, array<string, mixed>>, consumed: array<int, true>}
     */
    private function heroes(array $items, array $media, int $cardWidth): array
    {
        $byImage  = [];
        $consumed = [];

        foreach ($items as $index => $item) {
            if ($item['kind'] !== 'image' || ! isset($media[$item['src']])) {
                continue;
            }

            $width  = (float) ($item['width'] ?? 0);
            $height = (float) ($item['height'] ?? 0);

            if ($height <= 0
                || $width < $this->pageWidth * self::HERO_MIN_PAGE_SHARE
                || $width / $height < self::HERO_MIN_RATIO) {
                continue;
            }

            $left  = (float) $item['x'];
            $right = $left + $width;
            $top   = (float) $item['y'];

            // Text has to START above the bottom of the picture by a real
            // margin, or a caption set immediately beneath the banner — which
            // is where a caption goes — would be read as sitting on it.
            $floor = $top + $height - max(6.0, $height * 0.06);

            $on = [];

            foreach ($items as $other => $candidate) {
                if ($candidate['kind'] !== 'text' || isset($consumed[$other])) {
                    continue;
                }

                if ($candidate['y'] < $top - 2 || $candidate['y'] > $floor) {
                    continue;
                }

                $frame = (float) ($candidate['box']['width'] ?? 0);

                if ($candidate['x'] < $left - 4 || $candidate['x'] + $frame > $right + 4) {
                    continue;
                }

                $on[$other] = $candidate;
            }

            if ($on === [] || count($on) > self::HERO_MAX_TEXTS) {
                continue;
            }

            uasort($on, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

            // The headline is the biggest type on the banner, not the topmost —
            // a kicker or an eyebrow line often sits above it. `>` rather than
            // `>=` keeps the first of equals, which is reading order.
            $headingKey = null;

            foreach ($on as $key => $candidate) {
                if ($headingKey === null || $candidate['size'] > $on[$headingKey]['size']) {
                    $headingKey = $key;
                }
            }

            $rest = [];

            foreach ($on as $key => $candidate) {
                if ($key !== $headingKey) {
                    $rest[] = $candidate['text'];
                }
            }

            $block = [
                'type'       => 'hero',
                'image'      => $media[$item['src']]['url'],
                'alt'        => $this->tidy((string) ($item['alt'] ?? '')),
                'heading'    => $on[$headingKey]['text'],
                'subheading' => mb_substr(implode("\n", $rest), 0, 600),
                'position'   => $this->heroPosition($on, $left, $top, $width, $height),
                'text_color' => $this->heroTextColour($on),
                'overlay'    => 'soft',
                // Expressed against the card it will render into, so the email
                // keeps the proportions of the printed banner instead of a
                // height someone guessed.
                'height'     => max(120, min(480, (int) round($height / $this->pageWidth * $cardWidth))),
            ];

            $byImage[$index] = $block;

            foreach (array_keys($on) as $key) {
                $consumed[$key] = true;
            }
        }

        return ['byImage' => $byImage, 'consumed' => $consumed];
    }

    /**
     * Which ninth of the banner the type occupies, from where it actually sits.
     *
     * Taken from the whole group's bounding box rather than the headline alone:
     * a headline and its standfirst are one visual mass, and positioning on the
     * first line of it puts the pair higher than the designer set them.
     *
     * @param  array<int, array<string, mixed>>  $on
     */
    private function heroPosition(array $on, float $left, float $top, float $width, float $height): string
    {
        $x0 = INF;
        $x1 = -INF;
        $y0 = INF;
        $y1 = -INF;

        foreach ($on as $candidate) {
            $x0 = min($x0, (float) $candidate['x']);
            $x1 = max($x1, (float) $candidate['x'] + (float) ($candidate['box']['width'] ?? 0));
            $y0 = min($y0, (float) $candidate['y']);
            $y1 = max($y1, (float) $candidate['y'] + (float) ($candidate['box']['height'] ?? 0));
        }

        $across = $width > 0 ? ((($x0 + $x1) / 2) - $left) / $width : 0.5;
        $down   = $height > 0 ? ((($y0 + $y1) / 2) - $top) / $height : 0.5;

        $horizontal = $across < 0.38 ? 'left' : ($across > 0.62 ? 'right' : 'center');
        $vertical   = $down < 0.36 ? 'top' : ($down > 0.72 ? 'bottom' : 'middle');

        return $vertical . '_' . $horizontal;
    }

    /**
     * Whether the banner's type is light or dark, by the colour carrying most
     * of its characters.
     *
     * Worth reading rather than assuming: type set light on artwork is the case
     * deriveTheme already refuses to trust for the page palette, and this is the
     * one place in the email where that type still has a dark ground to sit on,
     * so here it should be believed.
     *
     * @param  array<int, array<string, mixed>>  $on
     */
    private function heroTextColour(array $on): string
    {
        $byColour = [];

        foreach ($on as $candidate) {
            if ($candidate['color'] === null) {
                continue;
            }

            $byColour[$candidate['color']] = ($byColour[$candidate['color']] ?? 0) + mb_strlen($candidate['text']);
        }

        if ($byColour === []) {
            return 'light';
        }

        arsort($byColour);

        return $this->luminance((string) array_key_first($byColour)) > 0.5 ? 'light' : 'dark';
    }

    /**
     * Matches each flat-colour band to the text sitting on it.
     *
     * The band and its title are two unrelated elements in the export — a
     * rectangle and a text frame that happen to occupy the same strip of page.
     * Pairing them by vertical overlap is the only thing that recovers the
     * relationship, and without it the deck loses the three green bands that
     * carry most of its structure.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, ?string>  $rules
     * @return array{byItem: array<int, array{style: string, colour: string}>, consumed: array<int, int>, colour: ?string}
     */
    private function bands(array $items, array $rules): array
    {
        $byItem   = [];
        $consumed = [];
        $colour   = null;

        foreach ($items as $index => $item) {
            if ($item['kind'] !== 'image' || ($rules[$item['src']] ?? null) === null) {
                continue;
            }

            $bandTop    = $item['y'];
            $bandBottom = $bandTop + ($item['height'] ?? 0);

            foreach ($items as $other => $candidate) {
                if ($candidate['kind'] !== 'text' || isset($byItem[$other])) {
                    continue;
                }

                // The text's baseline has to fall inside the rectangle. A
                // tolerance either side absorbs the sub-pixel offsets InDesign
                // emits, without reaching the next paragraph down.
                if ($candidate['y'] < $bandTop - 6 || $candidate['y'] > $bandBottom + 6) {
                    continue;
                }

                $byItem[$other] = [
                    // Narrower than most of the page is the inset pill; the
                    // full-width bars are the section bars.
                    'style'  => ($item['width'] ?? 0) > 0 && $item['width'] < ($this->pageWidth * 0.8)
                        ? 'inset_pill'
                        : 'full_bleed',
                    'colour' => $rules[$item['src']],
                ];

                $consumed[] = $index;
                $colour ??= $rules[$item['src']];

                break;
            }
        }

        return ['byItem' => $byItem, 'consumed' => $consumed, 'colour' => $colour];
    }

    /**
     * Groups images that sit beside each other into rows.
     *
     * Reported by frontend: three industry photographs side by side in the
     * source came out stacked. They were never stacked in the export — they
     * share a `y` and differ in `x`, which is exactly what "in a row" looks
     * like on a page. Nothing read that until now.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $media
     * @param  array<int, mixed>  $skip  item indexes already claimed as banners
     * @return array<int, array<int, int>>  keyed by the row's first item index
     */
    private function imageRows(array $items, array $media, array $skip = []): array
    {
        $rows    = [];
        $current = [];
        $anchor  = null;

        $close = function () use (&$rows, &$current, &$anchor, $items): void {
            // Two is a row. One is just an image, and the renderer would strand
            // it at a third of the width with empty cells beside it.
            if (count($current) >= 2) {
                // Keyed on the first of them the block builder will reach, which
                // is the topmost — that is where the row is emitted.
                $first = min($current);

                // Ordered left to right by where they sit, NOT by the order they
                // were collected in. Items are sorted by y first and InDesign
                // nudges the pictures in a row a pixel or two apart vertically,
                // so collection order shuffles them across the row.
                usort($current, fn ($a, $b) => $items[$a]['x'] <=> $items[$b]['x']);

                // Three is the most a 620px card can hold and stay legible.
                $rows[$first] = array_slice($current, 0, 3);
            }

            $current = [];
            $anchor  = null;
        };

        foreach ($items as $index => $item) {
            if ($item['kind'] !== 'image' || ! isset($media[$item['src']]) || isset($skip[$index])) {
                // Anything else between two images — a heading, a paragraph —
                // means they are not in the same row, whatever their y says.
                // A banner ends a row for the same reason: it is a full-width
                // element, so nothing can be beside it.
                if ($item['kind'] === 'text' || isset($skip[$index])) {
                    $close();
                }

                continue;
            }

            if ($anchor !== null && abs($item['y'] - $anchor) <= self::ROW_Y_TOLERANCE && count($current) < 3) {
                $current[] = $index;
                continue;
            }

            $close();

            $current = [$index];
            $anchor  = $item['y'];
        }

        $close();

        return $rows;
    }

    /**
     * The size the body copy is set in: the one carrying the most characters.
     * Using the mode rather than an average keeps a single huge display line
     * from dragging the baseline up and demoting every real heading.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function bodySize(array $items): float
    {
        $bySize = [];

        foreach ($items as $item) {
            if ($item['kind'] !== 'text' || $item['size'] <= 0) {
                continue;
            }

            $key          = (string) round($item['size'], 1);
            $bySize[$key] = ($bySize[$key] ?? 0) + mb_strlen($item['text']);
        }

        if ($bySize === []) {
            return 0.0;
        }

        arsort($bySize);

        return (float) array_key_first($bySize);
    }

    /**
     * Distinct sizes above the body baseline, largest first — the document's own
     * heading hierarchy, whatever point sizes it happens to use.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, float>
     */
    private function headingSizes(array $items, float $body): array
    {
        if ($body <= 0) {
            return [];
        }

        $sizes = [];

        foreach ($items as $item) {
            if ($item['kind'] === 'text' && $item['size'] > $body * 1.15) {
                $sizes[(string) round($item['size'], 1)] = round($item['size'], 1);
            }
        }

        $sizes = array_values($sizes);
        rsort($sizes);

        return $sizes;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, float>  $headings
     */
    private function headingLevel(array $item, float $body, array $headings): ?string
    {
        if ($body <= 0 || $item['size'] <= 0) {
            return null;
        }

        // A long line is a paragraph however it is set. Without this a
        // large-type pull quote becomes a heading the width of the email.
        if (mb_strlen($item['text']) > 120) {
            return null;
        }

        $rank = array_search(round($item['size'], 1), $headings, true);

        if ($rank === false) {
            // Not larger than the body, but set in bold caps on its own short
            // line — a section label.
            return $item['bold'] && $item['upper'] && mb_strlen($item['text']) <= 60 ? 'small' : null;
        }

        return match ($rank) {
            0       => 'large',
            1       => 'medium',
            default => 'small',
        };
    }

    // -------------------------------------------------------------------------

    /**
     * Reads the x/y out of a `transform: translate(...)`, in whichever of the
     * four vendor spellings InDesign emitted. Percentages are ignored — the
     * export only ever uses pixels, and a percentage of an unknown box is not
     * something to guess at.
     *
     * @return array{0: float, 1: float}
     */
    private function readTranslate(string $declarations): array
    {
        if (! preg_match('/(?:^|[^-a-z])transform\s*:[^;]*?translate\(\s*(-?[\d.]+)px\s*,\s*(-?[\d.]+)px/i', $declarations, $m)) {
            return [0.0, 0.0];
        }

        return [(float) $m[1], (float) $m[2]];
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $document = new \DOMDocument();

        // InDesign's output is XHTML-flavoured and libxml complains about it;
        // none of those complaints affect what is read back out.
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** Collapses InDesign's soft hyphens, non-breaking spaces and runs of space. */
    private function tidy(string $text): string
    {
        $text = str_replace(["\xC2\xAD", "\xC2\xA0"], ['', ' '], $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
