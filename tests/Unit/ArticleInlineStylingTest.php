<?php

namespace Tests\Unit;

use App\Services\ArticleHtmlSanitizer;
use Tests\TestCase;

/**
 * The editor can style inline (Session 118) — class and style attributes on
 * the content elements — while everything still passes through HTMLPurifier,
 * so a style survives only when every declaration is on the CSS allowlist.
 */
class ArticleInlineStylingTest extends TestCase
{
    private function clean(string $html): string
    {
        return app(ArticleHtmlSanitizer::class)->sanitize($html);
    }

    public function test_classes_and_safe_styles_survive_on_content_elements(): void
    {
        $out = $this->clean(
            '<h2 class="callout" style="color:#b8460f">Heading</h2>'
            . '<p class="lead" style="font-size:1.2rem;line-height:1.8">Text</p>'
            . '<a href="https://okelcor.com/shop" class="btn" style="background-color:#f4511e;color:#fff">Shop</a>'
        );

        $this->assertStringContainsString('class="callout"', $out);
        $this->assertStringContainsString('color:#b8460f', $out);
        $this->assertStringContainsString('class="lead"', $out);
        $this->assertStringContainsString('font-size:1.2rem', $out);
        $this->assertStringContainsString('background-color:#f4511e', $out);
    }

    public function test_dangerous_declarations_are_stripped_not_trusted(): void
    {
        // position could overlay the site chrome; background-image loads a
        // URL; expression is IE-era script-in-CSS. All must die in the wash
        // while the safe part of the same style attribute survives.
        $out = $this->clean(
            '<p style="color:red;position:fixed;top:0;background-image:url(https://evil.test/x.png)">Text</p>'
        );

        $this->assertStringContainsString('color:#FF0000', $out); // purifier normalises the keyword
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('background-image', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_script_vectors_still_die(): void
    {
        $out = $this->clean(
            '<p class="x" onclick="alert(1)">Text</p>'
            . '<a href="javascript:alert(1)" class="y">bad link</a>'
            . '<script>alert(1)</script>'
        );

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('<script', $out);
        // The inert parts stay.
        $this->assertStringContainsString('class="x"', $out);
    }
}
