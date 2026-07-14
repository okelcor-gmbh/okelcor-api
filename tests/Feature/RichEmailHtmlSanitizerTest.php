<?php

namespace Tests\Feature;

use App\Services\RichEmailHtmlSanitizer;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pure string-transform + filesystem service — no database involved, so
 * (unlike most of this suite) this runs fine under the default sqlite
 * testing environment with no MySQL requirement.
 */
class RichEmailHtmlSanitizerTest extends TestCase
{
    private RichEmailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new RichEmailHtmlSanitizer();
        Storage::fake('public');
    }

    public function test_strips_script_tags_and_content(): void
    {
        $out = $this->sanitizer->sanitize('<p>Hi</p><script>alert(1)</script>', 'test');

        $this->assertStringContainsString('<p>Hi</p>', $out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $out = $this->sanitizer->sanitize('<p onclick="evil()">Signed</p>', 'test');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('Signed', $out);
    }

    public function test_strips_javascript_url_but_keeps_legit_link(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="javascript:alert(1)">bad</a><a href="https://okelcor.com">good</a>',
            'test'
        );

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('href="https://okelcor.com"', $out);
    }

    public function test_strips_css_expression_in_style_attribute(): void
    {
        $out = $this->sanitizer->sanitize(
            '<p style="width:expression(alert(1))">x</p>',
            'test'
        );

        $this->assertStringNotContainsString('expression', $out);
    }

    public function test_unwraps_unknown_tags_but_keeps_content(): void
    {
        $out = $this->sanitizer->sanitize('<weirdtag>Kept text</weirdtag>', 'test');

        $this->assertStringContainsString('Kept text', $out);
        $this->assertStringNotContainsString('weirdtag', $out);
    }

    public function test_strips_word_namespace_tags_but_keeps_content(): void
    {
        $out = $this->sanitizer->sanitize('<o:p>Hello <b>World</b></o:p>', 'test');

        $this->assertStringNotContainsString('o:p', $out);
        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString('<b>World</b>', $out);
    }

    public function test_extracts_valid_inline_image_to_storage(): void
    {
        // 1x1 transparent PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        $out = $this->sanitizer->sanitize(
            '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="logo">',
            'signatures/42'
        );

        $this->assertStringNotContainsString('data:image', $out);
        $this->assertMatchesRegularExpression('#src="https?://[^"]+/storage/signatures/42/[^"]+\.png"#', $out);

        preg_match('#/storage/(signatures/42/[^"]+\.png)#', $out, $m);
        Storage::disk('public')->assertExists($m[1]);
    }

    public function test_drops_corrupt_base64_image_without_storing_broken_file(): void
    {
        $out = $this->sanitizer->sanitize(
            '<p>Before</p><img src="data:image/png;base64,not-a-real-image!!!" alt="bad"><p>After</p>',
            'signatures/99'
        );

        $this->assertStringContainsString('Before', $out);
        $this->assertStringContainsString('After', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertCount(0, Storage::disk('public')->files('signatures/99'));
    }

    public function test_leaves_external_https_image_untouched(): void
    {
        $out = $this->sanitizer->sanitize('<img src="https://okelcor.com/logo.png" alt="logo">', 'test');

        $this->assertStringContainsString('src="https://okelcor.com/logo.png"', $out);
        $this->assertCount(0, Storage::disk('public')->allFiles('test'));
    }

    public function test_preserves_table_font_signature_markup(): void
    {
        $out = $this->sanitizer->sanitize(
            '<table><tr><td><font face="Arial" size="2" color="#333333">John Doe</font><br><small>Sales Manager</small></td></tr></table>',
            'test'
        );

        $this->assertStringContainsString('<table>', $out);
        $this->assertStringContainsString('font face="Arial"', $out);
        $this->assertStringContainsString('John Doe', $out);
        $this->assertStringContainsString('<small>Sales Manager</small>', $out);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize('', 'test'));
        $this->assertSame('', $this->sanitizer->sanitize(null, 'test'));
        $this->assertSame('', $this->sanitizer->sanitize('   ', 'test'));
    }
}
