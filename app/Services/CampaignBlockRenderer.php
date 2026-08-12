<?php

namespace App\Services;

/**
 * Turns a campaign built out of BLOCKS into email-safe HTML.
 *
 * The marketing team is not technical and was previously either pasting a
 * hand-written HTML document into the campaign body or reproducing the layout
 * they used to get from Wix's drag-and-drop editor. Instead of asking them to
 * write HTML, they compose a list of blocks — heading, text, image, button,
 * list, spacer, divider, footer — and this renders the Okelcor house style
 * around it, modelled on the Wix campaigns they were already sending: a teal
 * page, a dark centred card, centred headings, full-width images, and a teal
 * call-to-action button.
 *
 * Everything is table-based with inline styles, because that is the only thing
 * Outlook renders reliably — no flexbox, no grid, no <style> selectors that
 * matter for layout (the one <style> block carries mobile-only overrides that
 * clients which ignore it simply don't need).
 *
 * Text is ESCAPED, never trusted: the only markup a marketer can produce is
 * what this class emits itself, from a tiny inline syntax (**bold**, *italic*,
 * [label](url)). So a non-technical user cannot accidentally — or a compromised
 * admin account deliberately — inject script into a mail going to the whole
 * contact list.
 */
class CampaignBlockRenderer
{
    /**
     * Named colour/typography presets. `okelcor_dark` reproduces the campaigns
     * the team was sending from Wix; `light` is the same layout on a light page
     * for markets/lists where dark reads as heavy.
     */
    public const THEMES = [
        'okelcor_dark' => [
            'label'             => 'Okelcor dark (house style)',
            'background'        => '#2E6E75',
            'card_background'   => '#2B2B2B',
            'text_color'        => '#FFFFFF',
            'heading_color'     => '#FFFFFF',
            'muted_color'       => '#C9C9C9',
            'button_background' => '#2E6E75',
            'button_text_color' => '#FFFFFF',
            'link_color'        => '#7FD3DB',
            'divider_color'     => '#464646',
            // Section bands and card tiles. Separate keys rather than reusing
            // button/card colours: a band is a different job from a button, and
            // tying them together means one cannot be restyled without the other.
            'band_background'       => '#2E6E75',
            'band_text_color'       => '#FFFFFF',
            'band_dark_background'  => '#1B1B1B',
            'band_muted_background' => '#3A3A3A',
            'card_surface'          => '#363636',
            'font_family'       => "Arial, Helvetica, sans-serif",
            'card_width'        => 620,
        ],
        'light' => [
            'label'             => 'Light',
            'background'        => '#F1F4F5',
            'card_background'   => '#FFFFFF',
            'text_color'        => '#333333',
            'heading_color'     => '#14343A',
            'muted_color'       => '#6B7280',
            'button_background' => '#2E6E75',
            'button_text_color' => '#FFFFFF',
            'link_color'        => '#1D6F77',
            'divider_color'     => '#E1E5E7',
            'band_background'       => '#2E6E75',
            'band_text_color'       => '#FFFFFF',
            'band_dark_background'  => '#14343A',
            'band_muted_background' => '#E8EDEE',
            'card_surface'          => '#F4F7F7',
            'font_family'       => "Arial, Helvetica, sans-serif",
            'card_width'        => 620,
        ],
        /**
         * Fuel Eco Tech. A separate product with its own design system, so a FET
         * campaign starts correct instead of being hand-corrected off an Okelcor
         * preset every time.
         *
         * The green is #1F8A5B — read out of the marketers' own InDesign file,
         * not chosen here. Note it is NOT the #22c55e the FET web UI documents:
         * that is a bright accent tuned for buttons on a dark interface, and on
         * a white email band it reads neon. The dark tone below IS the
         * documented #0D2B1A. One constant either way if the business decides
         * otherwise.
         */
        'fet_green' => [
            'label'             => 'Fuel Eco Tech (green)',
            'background'        => '#FFFFFF',
            'card_background'   => '#FFFFFF',
            'text_color'        => '#1F2937',
            'heading_color'     => '#14532D',
            'muted_color'       => '#6B7280',
            'button_background' => '#1F8A5B',
            'button_text_color' => '#FFFFFF',
            'link_color'        => '#1F8A5B',
            'divider_color'     => '#E1E5E7',
            'band_background'       => '#1F8A5B',
            'band_text_color'       => '#FFFFFF',
            'band_dark_background'  => '#0D2B1A',
            'band_muted_background' => '#EEF2F0',
            'card_surface'          => '#F0F4F1',
            'font_family'       => "Arial, Helvetica, sans-serif",
            // Wider than the other two: this deck's grid is three across, and at
            // 620 the columns fall under 180px. Still inside resolveTheme's cap.
            'card_width'        => 680,
        ],
    ];

    public const DEFAULT_THEME = 'okelcor_dark';

    /**
     * The block catalogue. Served to the frontend as-is (see
     * AdminCampaignTemplateController::blocks) so the editor UI is generated
     * from this single definition instead of a hardcoded copy that can drift.
     */
    public const BLOCKS = [
        'heading' => [
            'label'       => 'Heading',
            'description' => 'A bold title. Use one at the top, then one above each section.',
            'fields'      => [
                'text'  => ['type' => 'text', 'label' => 'Heading text', 'required' => true, 'max' => 300],
                'level' => ['type' => 'select', 'label' => 'Size', 'options' => ['large', 'medium', 'small'], 'default' => 'large'],
                'align' => ['type' => 'select', 'label' => 'Alignment', 'options' => ['left', 'center', 'right'], 'default' => 'center'],
            ],
        ],
        'text' => [
            'label'       => 'Paragraph',
            'description' => 'A block of text. Supports **bold**, *italic* and [link text](https://…).',
            'fields'      => [
                'text'  => ['type' => 'textarea', 'label' => 'Text', 'required' => true, 'max' => 5000],
                'align' => ['type' => 'select', 'label' => 'Alignment', 'options' => ['left', 'center', 'right'], 'default' => 'left'],
                'size'  => ['type' => 'select', 'label' => 'Text size', 'options' => ['normal', 'large', 'small'], 'default' => 'normal'],
            ],
        ],
        'image' => [
            'label'       => 'Image',
            'description' => 'A full-width picture. Pick one from the Media Library.',
            'fields'      => [
                'url'  => ['type' => 'image_url', 'label' => 'Image', 'required' => true],
                'alt'  => ['type' => 'text', 'label' => 'Description (for screen readers / blocked images)', 'max' => 200],
                'link' => ['type' => 'url', 'label' => 'Link when clicked (optional)'],
            ],
        ],
        'image_row' => [
            'label'       => 'Images side by side',
            'description' => 'Two or three pictures in one row. They stack automatically on a phone.',
            'fields'      => [
                'image_1' => ['type' => 'image_url', 'label' => 'First image', 'required' => true],
                'alt_1'   => ['type' => 'text', 'label' => 'First image description', 'max' => 200],
                'image_2' => ['type' => 'image_url', 'label' => 'Second image', 'required' => true],
                'alt_2'   => ['type' => 'text', 'label' => 'Second image description', 'max' => 200],
                'image_3' => ['type' => 'image_url', 'label' => 'Third image (optional)'],
                'alt_3'   => ['type' => 'text', 'label' => 'Third image description', 'max' => 200],
            ],
        ],
        'section_header' => [
            'label'       => 'Section band',
            'description' => 'A coloured band that introduces a section.',
            'fields'      => [
                'text'  => ['type' => 'text', 'label' => 'Band text', 'required' => true, 'max' => 200],
                'style' => ['type' => 'select', 'label' => 'Width', 'options' => ['full_bleed', 'inset_pill'], 'default' => 'full_bleed'],
                // Named tones rather than a colour picker: the colour decision
                // belongs to the theme, so a band cannot drift away from the
                // rest of the campaign one edit at a time.
                'tone'  => ['type' => 'select', 'label' => 'Colour', 'options' => ['accent', 'dark', 'muted'], 'default' => 'accent'],
            ],
        ],
        'cards' => [
            'label'       => 'Cards',
            'description' => 'A grid of short titled points, two or three across. They stack on a phone.',
            'fields'      => [
                'columns' => ['type' => 'select', 'label' => 'Cards per row', 'options' => ['2', '3'], 'default' => '3'],
                'check'   => ['type' => 'select', 'label' => 'Show a tick on each card', 'options' => ['yes', 'no'], 'default' => 'yes'],
                'items'   => [
                    'type'        => 'group_list',
                    'label'       => 'Cards',
                    'max_items'   => 24,
                    'item_fields' => [
                        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'max' => 120],
                        'body'  => ['type' => 'textarea', 'label' => 'Description', 'max' => 300],
                    ],
                ],
            ],
        ],
        'button' => [
            'label'       => 'Button',
            'description' => 'The call to action. Keep the label short.',
            'fields'      => [
                'label' => ['type' => 'text', 'label' => 'Button text', 'required' => true, 'max' => 80],
                'url'   => ['type' => 'url', 'label' => 'Where it goes', 'required' => true],
                'align' => ['type' => 'select', 'label' => 'Alignment', 'options' => ['left', 'center', 'right'], 'default' => 'center'],
            ],
        ],
        'list' => [
            'label'       => 'Bullet list',
            'description' => 'Short points, one per line.',
            'fields'      => [
                'items' => ['type' => 'text_list', 'label' => 'Points', 'required' => true, 'max_items' => 20],
            ],
        ],
        'divider' => [
            'label'       => 'Divider',
            'description' => 'A thin line between sections.',
            'fields'      => [],
        ],
        'spacer' => [
            'label'       => 'Spacer',
            'description' => 'Empty vertical space.',
            'fields'      => [
                'height' => ['type' => 'number', 'label' => 'Height in pixels', 'default' => 24, 'min' => 4, 'max' => 120],
            ],
        ],
        'footer' => [
            'label'       => 'Footer',
            'description' => 'Company address, social links and a link to the website. Put this last.',
            'fields'      => [
                'address_lines' => ['type' => 'text_list', 'label' => 'Address lines', 'max_items' => 6],
                'social'        => ['type' => 'link_list', 'label' => 'Social links', 'max_items' => 6],
                'site_label'    => ['type' => 'text', 'label' => 'Website link text', 'default' => 'Check out our site', 'max' => 80],
                'site_url'      => ['type' => 'url', 'label' => 'Website address'],
            ],
        ],
    ];

    /**
     * Renders blocks to a complete HTML document.
     *
     * `[[UNSUBSCRIBE_URL]]` and the other merge tags are left as literal tokens
     * — SendBulkEmailCampaignJob substitutes them per recipient, so one render
     * serves the whole send.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $theme  preset name under 'preset', plus any per-campaign overrides
     */
    public function render(array $blocks, array $theme = []): string
    {
        $t = $this->resolveTheme($theme);

        $width = (int) $t['card_width'];

        // Anything narrower than the card plus the page gutter has to collapse,
        // or it scrolls sideways.
        $breakpoint = $width + 40;

        // One row per block, so a block can decide whether it sits inside the
        // card's horizontal padding or runs the full width of it. Previously
        // every block shared one padded cell, which meant `full_bleed` section
        // bands were inset by 34px — i.e. not full bleed, which is the one
        // thing their name promises.
        $content = '';

        foreach ($blocks as $block) {
            $html = $this->renderBlock($block, $t);

            if ($html === '') {
                continue;
            }

            $padding = $this->bleeds($block) ? '0' : '0 34px';

            $content .= '<tr><td class="' . ($this->bleeds($block) ? 'ok-bleed' : 'ok-pad') . '" style="padding:' . $padding . ';">'
                . $html . '</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title></title>
<style type="text/css">
/* Mobile only. Layout never depends on these — a client that drops the
   <style> block still gets the full-width table layout below.

   The breakpoint is derived from the card width rather than fixed, because
   card_width is a per-theme setting that can reach 800: a hardcoded 620 left
   every viewport between the breakpoint and the card width scrolling
   sideways, which is exactly the band a small tablet sits in. */
@media only screen and (max-width: {$breakpoint}px) {
  .ok-card { width: 100% !important; max-width: 100% !important; }
  .ok-pad { padding-left: 20px !important; padding-right: 20px !important; }
  .ok-bleed { padding-left: 0 !important; padding-right: 0 !important; }
  .ok-stack { display: block !important; width: 100% !important; max-width: 100% !important; text-align: center !important; padding: 8px 0 !important; }
  /* Spacer cells exist only to stop a short final row of tiles stretching on
     a wide screen. Stacked, they are empty full-width blocks, so they become
     stray gaps in the middle of the grid. */
  .ok-hide-sm { display: none !important; }
  img { max-width: 100% !important; height: auto !important; }
}
</style>
</head>
<body style="margin:0; padding:0; background-color:{$t['background']}; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:{$t['background']};">
<tr>
<td align="center" style="padding:28px 12px;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="{$width}" class="ok-card" style="width:{$width}px; max-width:{$width}px; background-color:{$t['card_background']};">
<tr><td style="height:36px; line-height:36px; font-size:0;">&nbsp;</td></tr>
{$content}
<tr><td style="height:24px; line-height:24px; font-size:0;">&nbsp;</td></tr>
</table>

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="{$width}" class="ok-card" style="width:{$width}px; max-width:{$width}px;">
<tr>
<td align="center" style="padding:18px 12px 6px 12px; font-family:{$t['font_family']}; font-size:12px; line-height:18px; color:{$t['text_color']};">
You are receiving this email from Okelcor.<br />
<a href="[[UNSUBSCRIBE_URL]]" style="color:{$t['text_color']}; text-decoration:underline;">Unsubscribe</a>
</td>
</tr>
</table>

</td>
</tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Plain-text alternative for the same blocks. Sent as the text part of the
     * message: a bulk HTML-only mail is markedly more likely to be filtered as
     * spam, and some recipients read text only.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function renderText(array $blocks): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';

            switch ($type) {
                case 'heading':
                    $out[] = strtoupper($this->plain($block['text'] ?? ''));
                    break;
                case 'text':
                    $out[] = $this->plain($block['text'] ?? '');
                    break;
                case 'list':
                    foreach ($this->stringList($block['items'] ?? []) as $item) {
                        $out[] = '- ' . $this->plain($item);
                    }
                    break;
                case 'button':
                    $out[] = $this->plain($block['label'] ?? '') . ': ' . ($block['url'] ?? '');
                    break;
                case 'image':
                    if (! empty($block['alt'])) {
                        $out[] = '[' . $this->plain($block['alt']) . ']';
                    }
                    break;
                case 'image_row':
                    foreach ([1, 2, 3] as $slot) {
                        if (! empty($block['alt_' . $slot])) {
                            $out[] = '[' . $this->plain((string) $block['alt_' . $slot]) . ']';
                        }
                    }
                    break;
                case 'section_header':
                    // The band is the section title; upper-casing it keeps the
                    // structure legible in a text-only client, same as heading.
                    $out[] = strtoupper($this->plain($block['text'] ?? ''));
                    break;
                case 'cards':
                    foreach ($this->cardItems($block['items'] ?? []) as $item) {
                        $out[] = '* ' . $this->plain($item['title'])
                            . ($item['body'] === '' ? '' : ' — ' . $this->plain($item['body']));
                    }
                    break;
                case 'divider':
                    $out[] = str_repeat('-', 40);
                    break;
                case 'footer':
                    foreach ($this->stringList($block['address_lines'] ?? []) as $line) {
                        $out[] = $this->plain($line);
                    }
                    if (! empty($block['site_url'])) {
                        $out[] = ($this->plain($block['site_label'] ?? 'Website')) . ': ' . $block['site_url'];
                    }
                    foreach ($this->linkList($block['social'] ?? []) as $link) {
                        $out[] = $this->plain($link['label']) . ': ' . $link['url'];
                    }
                    break;
            }
        }

        $out[] = '';
        $out[] = 'You are receiving this email from Okelcor.';
        $out[] = 'Unsubscribe: [[UNSUBSCRIBE_URL]]';

        return implode("\n\n", array_filter($out, fn ($l) => $l !== null));
    }

    /**
     * Validates blocks with messages a non-technical user can act on
     * ("Block 2 (Button): …"), rather than a schema dump.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, string>  human-readable errors; empty means valid
     */
    public function validateBlocks(array $blocks): array
    {
        $errors = [];

        if (empty($blocks)) {
            return ['Add at least one block before saving.'];
        }

        if (count($blocks) > 60) {
            return ['A campaign can have at most 60 blocks.'];
        }

        foreach ($blocks as $i => $block) {
            $n = $i + 1;

            if (! is_array($block)) {
                $errors[] = "Block {$n}: not a valid block.";
                continue;
            }

            $type = $block['type'] ?? null;

            if (! is_string($type) || ! isset(self::BLOCKS[$type])) {
                $known = implode(', ', array_keys(self::BLOCKS));
                $errors[] = "Block {$n}: unknown type '" . (is_string($type) ? $type : gettype($type)) . "'. Valid types: {$known}.";
                continue;
            }

            $label = self::BLOCKS[$type]['label'];

            foreach (self::BLOCKS[$type]['fields'] as $field => $spec) {
                $value   = $block[$field] ?? null;
                $isEmpty = $value === null || $value === '' || $value === [];

                if (! empty($spec['required']) && $isEmpty) {
                    $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" is required.";
                    continue;
                }

                if ($isEmpty) {
                    continue;
                }

                switch ($spec['type']) {
                    case 'text':
                    case 'textarea':
                        if (! is_string($value)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be text.";
                        } elseif (isset($spec['max']) && mb_strlen($value) > $spec['max']) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" is too long (max {$spec['max']} characters).";
                        }
                        break;

                    case 'select':
                        if (! in_array($value, $spec['options'], true)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be one of: " . implode(', ', $spec['options']) . '.';
                        }
                        break;

                    case 'number':
                        if (! is_numeric($value)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be a number.";
                        }
                        break;

                    case 'url':
                    case 'image_url':
                        if (! is_string($value) || $this->safeUrl($value) === null) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be a full web address starting with http:// or https://.";
                        }
                        break;

                    case 'text_list':
                        if (! is_array($value)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be a list.";
                        } elseif (isset($spec['max_items']) && count($value) > $spec['max_items']) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" allows at most {$spec['max_items']} entries.";
                        }
                        break;

                    case 'group_list':
                        if (! is_array($value)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be a list.";
                            break;
                        }

                        if (isset($spec['max_items']) && count($value) > $spec['max_items']) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" allows at most {$spec['max_items']} entries.";
                        }

                        foreach ($value as $j => $item) {
                            $position = $j + 1;

                            if (! is_array($item)) {
                                $errors[] = "Block {$n} ({$label}): entry {$position} is not filled in.";
                                continue;
                            }

                            foreach ($spec['item_fields'] as $sub => $subSpec) {
                                $subValue = $item[$sub] ?? null;
                                $blank    = $subValue === null || $subValue === '';

                                if (! empty($subSpec['required']) && $blank) {
                                    $errors[] = "Block {$n} ({$label}): entry {$position} needs \"{$subSpec['label']}\".";
                                } elseif (! $blank && ! is_string($subValue)) {
                                    $errors[] = "Block {$n} ({$label}): entry {$position} — \"{$subSpec['label']}\" must be text.";
                                } elseif (! $blank && isset($subSpec['max']) && mb_strlen($subValue) > $subSpec['max']) {
                                    $errors[] = "Block {$n} ({$label}): entry {$position} — \"{$subSpec['label']}\" is too long (max {$subSpec['max']} characters).";
                                }
                            }
                        }
                        break;

                    case 'link_list':
                        if (! is_array($value)) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" must be a list of links.";
                            break;
                        }
                        if (isset($spec['max_items']) && count($value) > $spec['max_items']) {
                            $errors[] = "Block {$n} ({$label}): \"{$spec['label']}\" allows at most {$spec['max_items']} entries.";
                        }
                        foreach ($value as $j => $link) {
                            if (! is_array($link) || empty($link['label']) || empty($link['url']) || $this->safeUrl((string) $link['url']) === null) {
                                $errors[] = "Block {$n} ({$label}): link " . ($j + 1) . ' needs a name and a full web address.';
                            }
                        }
                        break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     */
    public function resolveTheme(array $theme): array
    {
        $preset = $theme['preset'] ?? self::DEFAULT_THEME;
        $base   = self::THEMES[$preset] ?? self::THEMES[self::DEFAULT_THEME];

        // Only known keys can be overridden, and colours must look like colours
        // — an arbitrary string would otherwise land straight in a style
        // attribute.
        foreach ($theme as $key => $value) {
            if ($key === 'preset' || $key === 'label' || ! array_key_exists($key, $base)) {
                continue;
            }

            if ($key === 'card_width') {
                $base[$key] = max(320, min(800, (int) $value));
                continue;
            }

            if ($key === 'font_family') {
                // Font stacks only: letters, spaces, commas, quotes, hyphens.
                if (is_string($value) && preg_match('/^[A-Za-z0-9 ,\'"\-]{3,120}$/', $value)) {
                    $base[$key] = $value;
                }
                continue;
            }

            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value)) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    // -------------------------------------------------------------------------

    /**
     * Whether a block runs the full width of the card rather than sitting
     * inside its horizontal padding.
     *
     * Only a full-bleed section band does. An inset pill is centred inside the
     * padding by definition, and body content inset by 34px is the entire point
     * of the card.
     *
     * @param  array<string, mixed>  $block
     */
    private function bleeds(array $block): bool
    {
        return ($block['type'] ?? '') === 'section_header'
            && ($block['style'] ?? 'full_bleed') === 'full_bleed';
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $t
     */
    private function renderBlock(array $block, array $t): string
    {
        return match ($block['type'] ?? '') {
            'heading'        => $this->heading($block, $t),
            'text'           => $this->text($block, $t),
            'image'          => $this->image($block, $t),
            'image_row'      => $this->imageRow($block, $t),
            'section_header' => $this->sectionHeader($block, $t),
            'cards'          => $this->cards($block, $t),
            'button'         => $this->button($block, $t),
            'list'           => $this->list($block, $t),
            'divider'        => $this->divider($t),
            'spacer'         => $this->spacer($block),
            'footer'         => $this->footer($block, $t),
            default          => '',
        };
    }

    private function heading(array $b, array $t): string
    {
        $sizes = ['large' => ['24px', '32px'], 'medium' => ['20px', '28px'], 'small' => ['17px', '24px']];
        [$size, $line] = $sizes[$b['level'] ?? 'large'] ?? $sizes['large'];
        $align = $this->align($b['align'] ?? 'center');
        $text  = $this->inline($b['text'] ?? '', $t);

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td align="{$align}" style="padding:0 0 16px 0; font-family:{$t['font_family']}; font-size:{$size}; line-height:{$line}; font-weight:bold; color:{$t['heading_color']}; text-align:{$align};">{$text}</td>
</tr></table>

HTML;
    }

    private function text(array $b, array $t): string
    {
        $sizes = ['small' => ['14px', '22px'], 'normal' => ['16px', '26px'], 'large' => ['18px', '29px']];
        [$size, $line] = $sizes[$b['size'] ?? 'normal'] ?? $sizes['normal'];
        $align = $this->align($b['align'] ?? 'left');
        $text  = $this->inline($b['text'] ?? '', $t);

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td align="{$align}" style="padding:0 0 18px 0; font-family:{$t['font_family']}; font-size:{$size}; line-height:{$line}; color:{$t['text_color']}; text-align:{$align};">{$text}</td>
</tr></table>

HTML;
    }

    private function image(array $b, array $t): string
    {
        $url = $this->safeUrl((string) ($b['url'] ?? ''));
        if ($url === null) {
            return '';
        }

        $alt = e((string) ($b['alt'] ?? ''));

        // Derived from the card rather than fixed at 552: a theme can set
        // card_width up to 800, and a hardcoded width leaves the picture
        // narrower than the column it sits in with a visible margin down one
        // side. 68 is the card's horizontal padding.
        $inner = max(200, (int) $t['card_width'] - 68);

        $img = '<img src="' . e($url) . '" alt="' . $alt . '" width="' . $inner . '" style="display:block; width:100%; max-width:' . $inner . 'px; height:auto; border:0; outline:none; text-decoration:none;" />';

        $link = isset($b['link']) ? $this->safeUrl((string) $b['link']) : null;
        if ($link !== null) {
            $img = '<a href="' . e($link) . '" style="text-decoration:none;">' . $img . '</a>';
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td align="center" style="padding:0 0 20px 0;">{$img}</td>
</tr></table>

HTML;
    }

    /**
     * Two or three images across one row.
     *
     * A table with one cell per image, not floats or flex — Outlook renders
     * through Word's engine, which supports neither. `.ok-stack` (already in
     * the head, already used by the footer) turns the cells into full-width
     * blocks under 620px: three columns inside a 620px card is under 180px
     * each, which is a thumbnail on a phone rather than a photograph.
     */
    private function imageRow(array $b, array $t): string
    {
        $images = [];

        foreach ([1, 2, 3] as $slot) {
            $url = $this->safeUrl((string) ($b['image_' . $slot] ?? ''));

            if ($url !== null) {
                $images[] = ['url' => $url, 'alt' => (string) ($b['alt_' . $slot] ?? '')];
            }
        }

        if ($images === []) {
            return '';
        }

        // One image is not a row. Falling through to the ordinary image block
        // renders it full width instead of stranding it at a third of the
        // width with two empty cells beside it.
        if (count($images) === 1) {
            return $this->image(['url' => $images[0]['url'], 'alt' => $images[0]['alt']], $t);
        }

        $count = count($images);
        $width = (int) floor(100 / $count);
        $cells = '';

        foreach ($images as $i => $image) {
            // Gutters as cell padding rather than margins, which Outlook drops.
            $left  = $i === 0 ? 0 : 6;
            $right = $i === $count - 1 ? 0 : 6;

            $cells .= '<td class="ok-stack" width="' . $width . '%" valign="top" style="padding:0 ' . $right . 'px 0 ' . $left . 'px;">'
                . '<img src="' . e($image['url']) . '" alt="' . e($image['alt']) . '" '
                . 'style="display:block; width:100%; max-width:100%; height:auto; border:0; outline:none; text-decoration:none;" />'
                . '</td>';
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
{$cells}
</tr></table>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td style="height:20px; line-height:20px; font-size:0;">&nbsp;</td></tr></table>

HTML;
    }

    /**
     * A coloured band introducing a section.
     *
     * `full_bleed` runs the width of the card; `inset_pill` is centred and only
     * as wide as its text. The colour comes from the theme by tone name, so a
     * band cannot drift away from the rest of the campaign one edit at a time.
     */
    private function sectionHeader(array $b, array $t): string
    {
        $text = $this->inline($b['text'] ?? '', $t, allowLinks: false);

        if (trim(strip_tags($text)) === '') {
            return '';
        }

        [$background, $color] = $this->bandColours($b['tone'] ?? 'accent', $t);

        $cell = "font-family:{$t['font_family']}; font-size:18px; line-height:26px; font-weight:bold; "
            . "color:{$color}; text-align:center; letter-spacing:0.5px;";

        // bgcolor as well as the style: Outlook honours the attribute more
        // reliably than the declaration, and a band that loses its colour is
        // white bold text on a white card — invisible.
        if (($b['style'] ?? 'full_bleed') === 'inset_pill') {
            return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td align="center" style="padding:6px 0 22px 0;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
  <td align="center" bgcolor="{$background}" style="background-color:{$background}; padding:14px 34px; {$cell}">{$text}</td>
  </tr></table>
</td>
</tr></table>

HTML;
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0;"><tr>
<td align="center" bgcolor="{$background}" style="background-color:{$background}; padding:16px 20px; {$cell}">{$text}</td>
</tr></table>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td style="height:22px; line-height:22px; font-size:0;">&nbsp;</td></tr></table>

HTML;
    }

    /**
     * @return array{0: string, 1: string}  background, and a text colour with
     *                                      enough contrast to survive on it
     */
    private function bandColours(string $tone, array $t): array
    {
        $background = match ($tone) {
            'dark'  => $t['band_dark_background'],
            'muted' => $t['band_muted_background'],
            default => $t['band_background'],
        };

        // The accent pair is declared together in the theme and trusted. For the
        // other two the background can be light or dark depending on the preset,
        // so the text colour is chosen rather than assumed — a muted band is
        // near-white in `light` and near-black in `okelcor_dark`.
        if ($tone === 'accent') {
            return [$background, $t['band_text_color']];
        }

        return [$background, $this->readableOn($background)];
    }

    /**
     * A grid of short titled points, two or three across.
     *
     * Rows of a fixed column count rather than a single wrapping row: email has
     * no wrapping layout, so the rows are built here. Cells are padded to a
     * consistent count so the last row's tiles keep their width instead of
     * stretching across the gap.
     */
    private function cards(array $b, array $t): string
    {
        $items = $this->cardItems($b['items'] ?? []);

        if ($items === []) {
            return '';
        }

        $columns = ((string) ($b['columns'] ?? '3')) === '2' ? 2 : 3;
        $check   = ((string) ($b['check'] ?? 'yes')) !== 'no';
        $width   = (int) floor(100 / $columns);

        $rows = '';

        foreach (array_chunk($items, $columns) as $chunk) {
            $cells = '';

            foreach ($chunk as $i => $item) {
                $left  = $i === 0 ? 0 : 5;
                $right = $i === $columns - 1 ? 0 : 5;

                $cells .= '<td class="ok-stack" width="' . $width . '%" valign="top" style="padding:0 ' . $right . 'px 10px ' . $left . 'px;">'
                    . $this->card($item, $t, $check)
                    . '</td>';
            }

            // Empty cells so a short final row does not stretch its tiles.
            // Hidden on a phone: stacked, they are empty full-width blocks and
            // become stray gaps in the middle of the grid.
            for ($i = count($chunk); $i < $columns; $i++) {
                $cells .= '<td class="ok-hide-sm" width="' . $width . '%" style="padding:0 0 10px 5px;">&nbsp;</td>';
            }

            $rows .= '<tr>' . $cells . '</tr>';
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
{$rows}
</table>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td style="height:14px; line-height:14px; font-size:0;">&nbsp;</td></tr></table>

HTML;
    }

    /** @param array{title: string, body: string} $item */
    private function card(array $item, array $t, bool $check): string
    {
        $title = $this->inline($item['title'], $t);
        $body  = $item['body'] === '' ? '' : $this->inline($item['body'], $t);

        // A square, not a circle: border-radius is ignored by Outlook, so a
        // "circle" would be a square there and a circle everywhere else. One
        // shape everywhere beats a shape that changes by client.
        $tick = $check
            ? '<td valign="top" width="30" style="padding:0 10px 0 0;">'
                . '<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>'
                . '<td align="center" width="24" height="24" bgcolor="' . $t['band_background'] . '" '
                . 'style="background-color:' . $t['band_background'] . '; width:24px; height:24px; '
                . 'font-family:' . $t['font_family'] . '; font-size:14px; line-height:24px; color:' . $t['band_text_color'] . ';">&#10003;</td>'
                . '</tr></table></td>'
            : '';

        $bodyRow = $body === ''
            ? ''
            : '<div style="font-family:' . $t['font_family'] . '; font-size:13px; line-height:20px; color:' . $t['muted_color'] . '; padding-top:5px;">' . $body . '</div>';

        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" '
            . 'bgcolor="' . $t['card_surface'] . '" style="background-color:' . $t['card_surface'] . '; height:100%;"><tr>'
            . '<td valign="top" style="padding:14px 14px 16px 14px;">'
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>'
            . $tick
            . '<td valign="top">'
            . '<div style="font-family:' . $t['font_family'] . '; font-size:15px; line-height:21px; font-weight:bold; color:' . $t['heading_color'] . ';">' . $title . '</div>'
            . $bodyRow
            . '</td>'
            . '</tr></table>'
            . '</td></tr></table>';
    }

    /**
     * @return array<int, array{title: string, body: string}>
     */
    private function cardItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';

            if ($title === '') {
                continue;
            }

            $out[] = [
                'title' => $title,
                'body'  => is_string($item['body'] ?? null) ? trim($item['body']) : '',
            ];
        }

        return $out;
    }

    /** Black or white, whichever survives on the given background. */
    private function readableOn(string $background): string
    {
        $hex = ltrim($background, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) < 6) {
            return '#FFFFFF';
        }

        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel((float) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((float) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((float) hexdec(substr($hex, 4, 2)));

        return $luminance > 0.45 ? '#111111' : '#FFFFFF';
    }

    private function button(array $b, array $t): string
    {
        $url = $this->safeUrl((string) ($b['url'] ?? ''));
        if ($url === null) {
            return '';
        }

        $label = $this->inline($b['label'] ?? '', $t, allowLinks: false);
        $align = $this->align($b['align'] ?? 'center');

        // Nested table rather than a padded <a>: Outlook ignores padding on
        // inline elements, which would collapse the button to bare text.
        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td align="{$align}" style="padding:6px 0 24px 0;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>
  <td align="center" bgcolor="{$t['button_background']}" style="background-color:{$t['button_background']}; padding:14px 30px;">
    <a href="{$url}" style="font-family:{$t['font_family']}; font-size:16px; line-height:20px; font-weight:bold; color:{$t['button_text_color']}; text-decoration:none; display:inline-block;">{$label}</a>
  </td>
  </tr></table>
</td>
</tr></table>

HTML;
    }

    private function list(array $b, array $t): string
    {
        $rows = '';

        foreach ($this->stringList($b['items'] ?? []) as $item) {
            $text = $this->inline($item, $t);
            $rows .= <<<HTML
  <tr>
  <td valign="top" style="padding:0 10px 10px 0; font-family:{$t['font_family']}; font-size:16px; line-height:26px; color:{$t['text_color']};">&bull;</td>
  <td valign="top" style="padding:0 0 10px 0; font-family:{$t['font_family']}; font-size:16px; line-height:26px; color:{$t['text_color']};">{$text}</td>
  </tr>

HTML;
        }

        if ($rows === '') {
            return '';
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="padding:0 0 12px 0;">
{$rows}</table>

HTML;
    }

    private function divider(array $t): string
    {
        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td style="padding:8px 0 24px 0;"><div style="height:1px; line-height:1px; font-size:0; background-color:{$t['divider_color']};">&nbsp;</div></td>
</tr></table>

HTML;
    }

    private function spacer(array $b): string
    {
        $height = max(4, min(120, (int) ($b['height'] ?? 24)));

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td style="height:{$height}px; line-height:{$height}px; font-size:0;">&nbsp;</td>
</tr></table>

HTML;
    }

    private function footer(array $b, array $t): string
    {
        $address = '';
        foreach ($this->stringList($b['address_lines'] ?? []) as $line) {
            $address .= e($line) . '<br />';
        }

        $social = '';
        foreach ($this->linkList($b['social'] ?? []) as $link) {
            $social .= '<a href="' . e($link['url']) . '" style="color:' . $t['link_color'] . '; text-decoration:none; padding:0 6px;">' . e($link['label']) . '</a>';
        }
        if ($social !== '') {
            $social = '<div style="padding-bottom:6px;">Share on social</div>' . $social;
        }

        $siteUrl = isset($b['site_url']) ? $this->safeUrl((string) $b['site_url']) : null;
        $site    = $siteUrl === null
            ? ''
            : '<a href="' . e($siteUrl) . '" style="color:' . $t['link_color'] . '; text-decoration:none;">'
                . e((string) ($b['site_label'] ?? 'Check out our site')) . ' &rarr;</a>';

        $cell = "font-family:{$t['font_family']}; font-size:13px; line-height:20px; color:{$t['muted_color']};";

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="padding:12px 0 0 0;"><tr>
<td class="ok-stack" width="40%" valign="top" align="left" style="{$cell} text-align:left;">{$address}</td>
<td class="ok-stack" width="30%" valign="top" align="center" style="{$cell} text-align:center;">{$social}</td>
<td class="ok-stack" width="30%" valign="top" align="right" style="{$cell} text-align:right;">{$site}</td>
</tr></table>

HTML;
    }

    // -------------------------------------------------------------------------

    /**
     * Escapes the text, then re-introduces ONLY the markup this method builds
     * itself from a small inline syntax. Nothing a marketer types can become a
     * tag, an attribute or a script.
     */
    private function inline(string $text, array $t, bool $allowLinks = true): string
    {
        $out = e($text);

        if ($allowLinks) {
            $out = preg_replace_callback(
                '/\[([^\]]{1,200})\]\(([^)\s]{1,500})\)/',
                function ($m) use ($t) {
                    // $m[2] is already escaped text; decode before validating
                    // the scheme so "https&#58;//" can't slip past.
                    $url = $this->safeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), allowMailto: true);

                    if ($url === null) {
                        return $m[1];
                    }

                    return '<a href="' . e($url) . '" style="color:' . $t['link_color'] . '; text-decoration:underline;">' . $m[1] . '</a>';
                },
                $out
            );
        }

        // Bold before italic: **x** would otherwise be eaten as *(*x*)*.
        $out = preg_replace('/\*\*([^*]{1,500})\*\*/', '<strong>$1</strong>', $out);
        $out = preg_replace('/(?<!\*)\*([^*]{1,500})\*(?!\*)/', '<em>$1</em>', $out);

        return nl2br($out, false);
    }

    /**
     * Absolute http/https (optionally mailto) only — returns null for anything
     * else, so `javascript:` and friends can never reach an href.
     */
    private function safeUrl(string $url, bool $allowMailto = false): ?string
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\r\n\t<>"]/', $url)) {
            return null;
        }

        // Merge tags resolve to a real URL at send time.
        if (str_starts_with($url, '[[') && str_ends_with($url, ']]')) {
            return $url;
        }

        if ($allowMailto && preg_match('/^mailto:[^\s@]+@[^\s@]+$/i', $url)) {
            return $url;
        }

        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    private function align(string $align): string
    {
        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    }

    private function plain(string $text): string
    {
        // Strips the inline syntax for the text part, keeping link labels.
        $text = preg_replace('/\[([^\]]*)\]\(([^)\s]*)\)/', '$1 ($2)', $text);
        $text = str_replace(['**', '*'], '', $text);

        return trim($text);
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', $value),
            fn ($v) => $v !== ''
        ));
    }

    /** @return array<int, array{label: string, url: string}> */
    private function linkList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $link) {
            if (! is_array($link) || empty($link['label']) || empty($link['url'])) {
                continue;
            }

            $url = $this->safeUrl((string) $link['url']);
            if ($url === null) {
                continue;
            }

            $out[] = ['label' => (string) $link['label'], 'url' => $url];
        }

        return $out;
    }
}
