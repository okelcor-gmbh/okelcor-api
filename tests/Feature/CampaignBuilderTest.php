<?php

namespace Tests\Feature;

use App\Models\MarketingContact;
use App\Services\CampaignBlockRenderer;
use App\Services\CampaignMergeTags;
use App\Support\CampaignStarterTemplates;
use Tests\TestCase;

/**
 * The block-based campaign builder: rendering, escaping, merge tags and the
 * built-in starter templates.
 *
 * No database — the renderer and the merge-tag substitution are pure functions
 * over arrays and strings, so these run everywhere including CI without the
 * MySQL gate the endpoint tests need. Endpoint coverage lives in
 * BulkEmailCampaignTest.
 */
class CampaignBuilderTest extends TestCase
{
    private CampaignBlockRenderer $renderer;
    private CampaignMergeTags $tags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new CampaignBlockRenderer();
        $this->tags     = new CampaignMergeTags();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function test_renders_the_house_style_shell(): void
    {
        $html = $this->renderer->render([
            ['type' => 'heading', 'text' => 'Accelerate Your Growth'],
        ]);

        // Teal page, dark card — the Wix look the team was already sending.
        $this->assertStringContainsString('background-color:#2E6E75', $html);
        $this->assertStringContainsString('background-color:#2B2B2B', $html);

        // Table-based with inline styles, because Outlook ignores everything else.
        $this->assertStringContainsString('role="presentation"', $html);
        $this->assertStringNotContainsString('display:flex', $html);

        // The unsubscribe token is left for per-recipient substitution.
        $this->assertStringContainsString('[[UNSUBSCRIBE_URL]]', $html);

        $this->assertStringContainsString('Accelerate Your Growth', $html);
    }

    public function test_renders_every_block_type(): void
    {
        $html = $this->renderer->render([
            ['type' => 'heading', 'text' => 'Title', 'level' => 'medium', 'align' => 'left'],
            ['type' => 'text', 'text' => 'A paragraph.', 'size' => 'large'],
            ['type' => 'image', 'url' => 'https://api.okelcor.com/storage/x.jpg', 'alt' => 'Tyres'],
            ['type' => 'button', 'label' => 'Get a Quote', 'url' => 'https://okelcor.com/contact'],
            ['type' => 'list', 'items' => ['PCR', 'TBR']],
            ['type' => 'divider'],
            ['type' => 'spacer', 'height' => 30],
            [
                'type'          => 'footer',
                'address_lines' => ['Landsbergerstr. 155', 'München'],
                'social'        => [['label' => 'Facebook', 'url' => 'https://facebook.com/okelcor']],
                'site_label'    => 'Check out our site',
                'site_url'      => 'https://okelcor.com',
            ],
        ]);

        $this->assertStringContainsString('Title', $html);
        $this->assertStringContainsString('A paragraph.', $html);
        $this->assertStringContainsString('src="https://api.okelcor.com/storage/x.jpg"', $html);
        $this->assertStringContainsString('alt="Tyres"', $html);
        $this->assertStringContainsString('Get a Quote', $html);
        $this->assertStringContainsString('href="https://okelcor.com/contact"', $html);
        $this->assertStringContainsString('PCR', $html);
        $this->assertStringContainsString('Landsbergerstr. 155', $html);
        $this->assertStringContainsString('Facebook', $html);
        $this->assertStringContainsString('height:30px', $html);
    }

    public function test_theme_preset_and_overrides(): void
    {
        $light = $this->renderer->render([['type' => 'text', 'text' => 'Hi']], ['preset' => 'light']);
        $this->assertStringContainsString('background-color:#FFFFFF', $light);

        $custom = $this->renderer->render(
            [['type' => 'button', 'label' => 'Go', 'url' => 'https://okelcor.com']],
            ['preset' => 'okelcor_dark', 'button_background' => '#123456']
        );
        $this->assertStringContainsString('#123456', $custom);
    }

    public function test_theme_rejects_values_that_are_not_colours(): void
    {
        // A style attribute is the one place a raw string must never land.
        $html = $this->renderer->render(
            [['type' => 'text', 'text' => 'Hi']],
            ['card_background' => 'red; background-image:url(javascript:alert(1))']
        );

        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('background-color:#2B2B2B', $html);
    }

    public function test_card_width_is_clamped(): void
    {
        $html = $this->renderer->render([['type' => 'text', 'text' => 'Hi']], ['card_width' => 99999]);
        $this->assertStringContainsString('width:800px', $html);

        $html = $this->renderer->render([['type' => 'text', 'text' => 'Hi']], ['card_width' => 10]);
        $this->assertStringContainsString('width:320px', $html);
    }

    // -------------------------------------------------------------------------
    // Escaping — a marketer's text must never become markup
    // -------------------------------------------------------------------------

    public function test_text_is_escaped_not_interpreted_as_html(): void
    {
        $html = $this->renderer->render([
            ['type' => 'heading', 'text' => '<script>alert(1)</script>'],
            ['type' => 'text', 'text' => '<img src=x onerror=alert(1)>'],
        ]);

        // The tags never exist as tags. The words survive as visible text —
        // which is the point: escaped, inert, and what the marketer typed.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_inline_formatting_produces_only_tags_we_generate(): void
    {
        $html = $this->renderer->render([
            ['type' => 'text', 'text' => "**bold** and *italic* and [our site](https://okelcor.com)"],
        ]);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('href="https://okelcor.com"', $html);
        $this->assertStringContainsString('>our site</a>', $html);
    }

    public function test_javascript_urls_are_rejected_everywhere(): void
    {
        $html = $this->renderer->render([
            ['type' => 'text', 'text' => '[click](javascript:alert(1))'],
            ['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)'],
            ['type' => 'image', 'url' => 'javascript:alert(1)'],
            ['type' => 'footer', 'social' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']]],
        ]);

        $this->assertStringNotContainsString('javascript:', $html);
        // The link label survives as plain text; only the href is dropped.
        $this->assertStringContainsString('click', $html);
        // Blocks whose whole purpose is the unusable URL render as nothing.
        $this->assertStringNotContainsString('>Go<', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_encoded_javascript_url_is_also_rejected(): void
    {
        $html = $this->renderer->render([
            ['type' => 'text', 'text' => '[click](javascript&#58;alert(1))'],
        ]);

        $this->assertStringNotContainsString('javascript', $html);
    }

    public function test_newlines_become_line_breaks(): void
    {
        $html = $this->renderer->render([['type' => 'text', 'text' => "Line one\nLine two"]]);

        $this->assertStringContainsString('<br>', $html);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_validation_names_the_block_and_the_field(): void
    {
        $errors = $this->renderer->validateBlocks([
            ['type' => 'heading', 'text' => 'Fine'],
            ['type' => 'button', 'label' => 'No URL'],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Block 2 (Button)', $errors[0]);
        $this->assertStringContainsString('Where it goes', $errors[0]);
    }

    public function test_validation_rejects_unknown_block_type_and_lists_valid_ones(): void
    {
        $errors = $this->renderer->validateBlocks([['type' => 'carousel']]);

        $this->assertStringContainsString("unknown type 'carousel'", $errors[0]);
        $this->assertStringContainsString('heading', $errors[0]);
    }

    public function test_validation_rejects_a_relative_image_url(): void
    {
        $errors = $this->renderer->validateBlocks([
            ['type' => 'image', 'url' => '/storage/x.jpg'],
        ]);

        $this->assertStringContainsString('http://', $errors[0]);
    }

    public function test_validation_rejects_an_empty_campaign(): void
    {
        $this->assertNotEmpty($this->renderer->validateBlocks([]));
    }

    public function test_valid_blocks_produce_no_errors(): void
    {
        foreach (CampaignStarterTemplates::all() as $starter) {
            $this->assertSame(
                [],
                $this->renderer->validateBlocks($starter['blocks']),
                "starter template '{$starter['key']}' must be valid"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Merge tags
    // -------------------------------------------------------------------------

    public function test_merge_tags_use_the_contacts_real_values(): void
    {
        $contact = new MarketingContact([
            'email'      => 'anna@example.com',
            'first_name' => 'Anna',
            'last_name'  => 'Novak',
            'company'    => 'Zagreb Tyres',
            'country'    => 'Croatia',
        ]);

        $out = $this->tags->apply(
            'Hi [[FIRST_NAME]] from [[COMPANY]] ([[EMAIL]]) — [[UNSUBSCRIBE_URL]]',
            $contact,
            'https://api.okelcor.com/unsub/abc'
        );

        $this->assertSame('Hi Anna from Zagreb Tyres (anna@example.com) — https://api.okelcor.com/unsub/abc', $out);
    }

    public function test_merge_tag_falls_back_when_the_contact_has_no_value(): void
    {
        // Most of the imported list is an email and nothing else, so "Hi ,"
        // going out to the whole list is the failure this prevents.
        $contact = new MarketingContact(['email' => 'x@example.com']);

        $this->assertSame(
            'Hi there,',
            $this->tags->apply('Hi [[FIRST_NAME|there]],', $contact, 'https://u')
        );

        // No fallback given → empty, never the raw token.
        $this->assertSame(
            'Hi ,',
            $this->tags->apply('Hi [[FIRST_NAME]],', $contact, 'https://u')
        );
    }

    public function test_unknown_merge_tag_is_reported_and_left_intact(): void
    {
        $contact = new MarketingContact(['email' => 'x@example.com']);

        // Left visible rather than blanked, so a typo is obvious in the preview.
        $this->assertSame(
            'Hi [[FIRSTNAME]]',
            $this->tags->apply('Hi [[FIRSTNAME]]', $contact, 'https://u')
        );

        $this->assertSame(['FIRSTNAME'], $this->tags->unknownTags('Hi [[FIRSTNAME]] and [[COMPANY]]'));
        $this->assertSame([], $this->tags->unknownTags('Hi [[FIRST_NAME|there]]'));
    }

    public function test_sample_values_fill_the_preview(): void
    {
        $out = $this->tags->applySamples('Hi [[FIRST_NAME]] at [[COMPANY]]');

        $this->assertStringNotContainsString('[[', $out);
        $this->assertStringContainsString('Anna', $out);
    }

    public function test_merge_tags_survive_rendering_and_url_validation(): void
    {
        // A tag inside a URL must not be rejected as "not a web address" before
        // it has had the chance to be substituted.
        $html = $this->renderer->render([
            ['type' => 'heading', 'text' => 'Hello [[FIRST_NAME|there]]'],
            ['type' => 'button', 'label' => 'Unsubscribe', 'url' => '[[UNSUBSCRIBE_URL]]'],
        ]);

        $this->assertStringContainsString('Hello [[FIRST_NAME|there]]', $html);
        $this->assertStringContainsString('href="[[UNSUBSCRIBE_URL]]"', $html);
    }

    // -------------------------------------------------------------------------
    // Plain-text alternative
    // -------------------------------------------------------------------------

    public function test_text_version_covers_the_content_without_markup(): void
    {
        $text = $this->renderer->renderText([
            ['type' => 'heading', 'text' => 'Big News'],
            ['type' => 'text', 'text' => 'We have **great** tyres.'],
            ['type' => 'list', 'items' => ['PCR', 'TBR']],
            ['type' => 'button', 'label' => 'Get a Quote', 'url' => 'https://okelcor.com/contact'],
        ]);

        $this->assertStringContainsString('BIG NEWS', $text);
        $this->assertStringContainsString('We have great tyres.', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('- PCR', $text);
        $this->assertStringContainsString('Get a Quote: https://okelcor.com/contact', $text);
        $this->assertStringContainsString('[[UNSUBSCRIBE_URL]]', $text);
    }

    // -------------------------------------------------------------------------
    // Starter templates
    // -------------------------------------------------------------------------

    public function test_okelcor_classic_reproduces_the_wix_campaign(): void
    {
        $starter = CampaignStarterTemplates::find('okelcor_classic');

        $this->assertNotNull($starter);

        $html = $this->renderer->render($starter['blocks'], $starter['theme']);

        // The landmarks from the campaign the team used to send.
        foreach ([
            'Accelerate Your Growth with OKELCOR TIRES',
            'Why Choose OKELCOR TIRES?',
            'Affordable Pricing',
            'Flexible Shipping Options',
            'Uncompromising Quality',
            'Explore Our Tire Solutions',
            'Contact Us Today',
            'Get a Custom Quote',
            'Landsbergerstr. 155 80687',
        ] as $landmark) {
            $this->assertStringContainsString($landmark, $html, "missing: {$landmark}");
        }
    }

    public function test_rendered_html_is_a_single_well_formed_document(): void
    {
        // A malformed or double-wrapped document renders unpredictably across
        // mail clients, and the marketer has no way to tell before it's sent.
        $starter = CampaignStarterTemplates::find('okelcor_classic');
        $html    = $this->renderer->render($starter['blocks'], $starter['theme']);

        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertSame(1, substr_count($html, '<body'));
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
        $this->assertSame(substr_count($html, '<tr'), substr_count($html, '</tr>'));
        $this->assertSame(substr_count($html, '<td'), substr_count($html, '</td>'));

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded   = $document->loadHTML($html);
        $fatal    = array_filter(libxml_get_errors(), fn ($e) => $e->level >= LIBXML_ERR_ERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($loaded);
        $this->assertSame([], array_values($fatal));
    }

    public function test_starters_are_distinct_and_complete(): void
    {
        $starters = CampaignStarterTemplates::all();

        $this->assertGreaterThanOrEqual(3, count($starters));
        $keys = array_column($starters, 'key');
        $this->assertSame($keys, array_unique($keys));

        foreach ($starters as $starter) {
            foreach (['key', 'name', 'description', 'theme', 'blocks'] as $field) {
                $this->assertArrayHasKey($field, $starter);
            }
            $this->assertNotEmpty($starter['blocks']);
        }

        $this->assertNull(CampaignStarterTemplates::find('nope'));
    }

    // ── multi-column blocks (Session 78) ──────────────────────────────────
    //
    // Frontend's finding: every block was a single full-width element, so a
    // design with anything side by side could only ever come out stacked.

    public function test_an_image_row_puts_its_pictures_in_one_row(): void
    {
        $html = $this->renderer->render([[
            'type'    => 'image_row',
            'image_1' => 'https://okelcor.com/a.png', 'alt_1' => 'Agriculture',
            'image_2' => 'https://okelcor.com/b.png', 'alt_2' => 'Cars',
            'image_3' => 'https://okelcor.com/c.png', 'alt_3' => 'Marine',
        ]]);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);

        // One row, three cells — not three rows of one.
        $row = $xpath->query('//img/ancestor::tr[1]');
        $this->assertSame(1, $row->length);
        $this->assertSame(3, $xpath->query('//img')->length);
        $this->assertSame(3, $xpath->query('//td[img]')->length);

        // Tables, never flex or grid — Outlook renders through Word's engine.
        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);

        // Three columns inside a 620px card is under 180px each. `.ok-stack`
        // is what turns them into full-width blocks on a phone, and it is
        // already declared in the head.
        $this->assertSame(3, $xpath->query('//td[contains(@class,"ok-stack")]')->length);
        $this->assertStringContainsString('.ok-stack', $html);
    }

    public function test_an_image_row_of_one_renders_full_width_instead_of_a_third(): void
    {
        $html = $this->renderer->render([[
            'type' => 'image_row', 'image_1' => 'https://okelcor.com/a.png', 'alt_1' => 'Only one',
        ]]);

        // Stranding a lone picture at a third of the width with two empty cells
        // beside it is worse than the plain image block. (`.ok-stack` still
        // appears in the head stylesheet — what must not appear is a cell
        // wearing it.)
        $this->assertStringContainsString('max-width:552px', $html);
        $this->assertStringNotContainsString('class="ok-stack"', $html);
    }

    public function test_a_section_header_carries_its_band_colour(): void
    {
        $html = $this->renderer->render(
            [['type' => 'section_header', 'text' => 'Benefits of the Fuel Eco Tech', 'style' => 'full_bleed', 'tone' => 'accent']],
            ['preset' => 'fet_green']
        );

        $this->assertStringContainsString('Benefits of the Fuel Eco Tech', $html);
        $this->assertStringContainsString('#1F8A5B', $html);

        // bgcolor as well as the declaration: Outlook honours the attribute
        // more reliably, and a band that loses its fill is white bold text on
        // a white card.
        $this->assertStringContainsString('bgcolor="#1F8A5B"', $html);
    }

    public function test_a_muted_band_picks_text_that_survives_on_it(): void
    {
        // The same tone is near-white in `light` and near-black in
        // `okelcor_dark`, so the text colour cannot be a constant.
        $onLight = $this->renderer->render(
            [['type' => 'section_header', 'text' => 'Section', 'tone' => 'muted']],
            ['preset' => 'light']
        );
        $onDark = $this->renderer->render(
            [['type' => 'section_header', 'text' => 'Section', 'tone' => 'muted']],
            ['preset' => 'okelcor_dark']
        );

        $this->assertStringContainsString('color:#111111', $onLight);
        $this->assertStringContainsString('color:#FFFFFF', $onDark);
    }

    public function test_cards_render_in_rows_of_the_chosen_width(): void
    {
        $items = [];
        for ($i = 1; $i <= 7; $i++) {
            $items[] = ['title' => "Benefit {$i}", 'body' => "What benefit {$i} does."];
        }

        $html = $this->renderer->render([['type' => 'cards', 'columns' => '3', 'items' => $items]]);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);

        // 7 cards at 3 across is 3 rows, and the last row is padded to 3 cells
        // so its single tile keeps its width instead of stretching.
        $grid = $xpath->query('(//td[contains(@class,"ok-stack")])[1]/ancestor::table[1]')->item(0);

        $this->assertNotNull($grid);
        $this->assertSame(3, $xpath->query('./tr', $grid)->length);
        $this->assertSame(9, $xpath->query('./tr/td', $grid)->length);

        $this->assertStringContainsString('Benefit 7', $html);
        $this->assertStringContainsString('What benefit 7 does.', $html);
    }

    public function test_a_card_without_a_title_is_dropped_rather_than_rendered_blank(): void
    {
        $html = $this->renderer->render([['type' => 'cards', 'items' => [
            ['title' => 'Real', 'body' => 'Kept'],
            ['title' => '', 'body' => 'Orphaned description'],
        ]]]);

        $this->assertStringContainsString('Real', $html);
        $this->assertStringNotContainsString('Orphaned description', $html);
    }

    public function test_the_text_part_covers_the_new_blocks(): void
    {
        $text = $this->renderer->renderText([
            ['type' => 'section_header', 'text' => 'Designed for'],
            ['type' => 'cards', 'items' => [['title' => 'Marine', 'body' => 'Boats and ferries.']]],
            ['type' => 'image_row', 'image_1' => 'https://okelcor.com/a.png', 'alt_1' => 'Fleet photo',
             'image_2' => 'https://okelcor.com/b.png', 'alt_2' => 'Marine photo'],
        ]);

        $this->assertStringContainsString('DESIGNED FOR', $text);
        $this->assertStringContainsString('* Marine — Boats and ferries.', $text);
        $this->assertStringContainsString('[Fleet photo]', $text);
        $this->assertStringContainsString('[Marine photo]', $text);
    }

    public function test_card_text_cannot_become_markup(): void
    {
        $html = $this->renderer->render([['type' => 'cards', 'items' => [[
            'title' => '<script>alert(1)</script>',
            'body'  => '<img src=x onerror=alert(1)>',
        ]]]]);

        // Escaped to visible text, so the literal words survive — as characters
        // on the page, which is the point. What must not exist is a tag or an
        // attribute.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        // The real risk is a tag, not the word: no <img> reached the document.
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_a_band_cannot_smuggle_a_link_or_markup(): void
    {
        $html = $this->renderer->render([[
            'type' => 'section_header',
            'text' => '[click](javascript:alert(1)) <b>raw</b>',
        ]]);

        // A band is not a link, so the inline link syntax is not honoured here
        // at all. The only href in the document is the unsubscribe footer the
        // renderer writes itself.
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertSame(1, substr_count($html, 'href='));
        $this->assertStringNotContainsString('<b>raw</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;raw&lt;/b&gt;', $html);
    }

    // ── group_list validation, the new field type ─────────────────────────

    public function test_group_list_reports_the_entry_that_needs_fixing(): void
    {
        $errors = $this->renderer->validateBlocks([[
            'type'  => 'cards',
            'items' => [['title' => 'Fine'], ['body' => 'No title on this one']],
        ]]);

        $this->assertNotEmpty($errors);
        // Phrased for a non-technical user and pointing at the entry, not the
        // schema — same contract as every other block error.
        $this->assertStringContainsString('entry 2', $errors[0]);
        $this->assertStringContainsString('Title', $errors[0]);
    }

    public function test_group_list_accepts_a_well_formed_grid(): void
    {
        $this->assertSame([], $this->renderer->validateBlocks([[
            'type'    => 'cards',
            'columns' => '2',
            'items'   => [['title' => 'One', 'body' => 'a'], ['title' => 'Two', 'body' => 'b']],
        ]]));
    }

    public function test_every_theme_declares_the_keys_the_new_blocks_read(): void
    {
        // A preset missing one of these renders a band or a tile with an empty
        // colour, which is a silently broken email rather than an error.
        foreach (CampaignBlockRenderer::THEMES as $preset => $values) {
            foreach (['band_background', 'band_text_color', 'band_dark_background', 'band_muted_background', 'card_surface'] as $key) {
                $this->assertArrayHasKey($key, $values, "{$preset} is missing {$key}");
                $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $values[$key]);
            }
        }
    }

    public function test_the_fet_preset_exists_and_is_green(): void
    {
        $this->assertArrayHasKey('fet_green', CampaignBlockRenderer::THEMES);

        // Read out of the marketers' own InDesign file, not chosen here.
        $this->assertSame('#1F8A5B', CampaignBlockRenderer::THEMES['fet_green']['band_background']);
        $this->assertSame('#0D2B1A', CampaignBlockRenderer::THEMES['fet_green']['band_dark_background']);
    }
}
