<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use PHPUnit\Framework\TestCase;

/**
 * The reverse converter must not honor the `data-djot-src` / `data-djot-raw`
 * round-trip attributes on UNTRUSTED input: they are re-emitted verbatim as raw
 * Djot, so a crafted value could smuggle a raw-HTML block (-> live <script>)
 * into the converted output. Honoring them is opt-in via the constructor flag,
 * intended only for trusted, djot-produced HTML.
 */
class HtmlToDjotRoundTripSecurityTest extends TestCase
{
    /**
     * A block-level `data-djot-src` payload smuggling a raw-HTML block.
     */
    protected function blockPayloadHtml(): string
    {
        $src = '`<script>alert(1)</script>`{=html}';

        return '<pre data-djot-src="' . htmlspecialchars($src, ENT_QUOTES) . '">code</pre>';
    }

    public function testDefaultConverterIgnoresBlockRoundTripAttribute(): void
    {
        $result = (new HtmlToDjot())->convert($this->blockPayloadHtml());

        // The raw-HTML round-trip source must NOT be re-emitted.
        $this->assertStringNotContainsString('{=html}', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testTrustedConverterHonorsBlockRoundTripAttribute(): void
    {
        $result = (new HtmlToDjot(trustedRoundTrip: true))->convert($this->blockPayloadHtml());

        // Trusted round-trip extraction re-emits the stored source verbatim.
        $this->assertStringContainsString('{=html}', $result);
    }

    public function testDefaultConverterIgnoresInlineRawAttribute(): void
    {
        $html = '<p>x <span data-djot-raw="html"><script>alert(1)</script></span></p>';

        $result = (new HtmlToDjot())->convert($html);

        // The inline raw round-trip span must NOT be re-emitted as `...`{=html}.
        $this->assertStringNotContainsString('{=html}', $result);
    }

    public function testTrustedConverterHonorsInlineRawAttribute(): void
    {
        $html = '<p>x <span data-djot-raw="html"><em>ok</em></span></p>';

        $result = (new HtmlToDjot(trustedRoundTrip: true))->convert($html);

        $this->assertStringContainsString('{=html}', $result);
    }

    public function testDefaultIsUntrusted(): void
    {
        // A bare `new HtmlToDjot()` must behave exactly like the untrusted path.
        $this->assertStringNotContainsString(
            '{=html}',
            (new HtmlToDjot())->convert($this->blockPayloadHtml()),
        );
    }
}
