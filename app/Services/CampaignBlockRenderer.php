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
            'font_family'       => "Arial, Helvetica, sans-serif",
            'card_width'        => 620,
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

        $content = '';
        foreach ($blocks as $block) {
            $content .= $this->renderBlock($block, $t);
        }

        $width = (int) $t['card_width'];

        return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title></title>
<style type="text/css">
/* Mobile only. Layout never depends on these — a client that drops the
   <style> block still gets the full-width table layout below. */
@media only screen and (max-width: 620px) {
  .ok-card { width: 100% !important; }
  .ok-pad { padding-left: 20px !important; padding-right: 20px !important; }
  .ok-stack { display: block !important; width: 100% !important; text-align: center !important; padding: 8px 0 !important; }
}
</style>
</head>
<body style="margin:0; padding:0; background-color:{$t['background']}; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:{$t['background']};">
<tr>
<td align="center" style="padding:28px 12px;">

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="{$width}" class="ok-card" style="width:{$width}px; max-width:{$width}px; background-color:{$t['card_background']};">
<tr>
<td class="ok-pad" style="padding:36px 34px;">
{$content}
</td>
</tr>
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
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $t
     */
    private function renderBlock(array $block, array $t): string
    {
        return match ($block['type'] ?? '') {
            'heading' => $this->heading($block, $t),
            'text'    => $this->text($block, $t),
            'image'   => $this->image($block, $t),
            'button'  => $this->button($block, $t),
            'list'    => $this->list($block, $t),
            'divider' => $this->divider($t),
            'spacer'  => $this->spacer($block),
            'footer'  => $this->footer($block, $t),
            default   => '',
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
        $img = '<img src="' . e($url) . '" alt="' . $alt . '" width="552" style="display:block; width:100%; max-width:552px; height:auto; border:0; outline:none; text-decoration:none;" />';

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
