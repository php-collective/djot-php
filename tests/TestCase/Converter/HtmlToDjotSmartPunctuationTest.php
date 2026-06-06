<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source-stable round-trip for Djot smart punctuation.
 *
 * The parser deterministically normalizes ASCII punctuation to typographic
 * characters: `...` becomes `…`, `--` becomes `–`, `---` becomes `—`. Those
 * transforms are one-directional in the parser, so a naive HtmlToDjot bakes the
 * typographic character into the regenerated source and the original ASCII is
 * lost across a Djot -> HTML -> Djot round-trip.
 *
 * HtmlToDjot reverses the dash and ellipsis transforms in prose text so the
 * regenerated Djot keeps the ASCII the author wrote. Because the parser mapping
 * is deterministic, the reversal stays HTML-stable: re-rendering the ASCII
 * reproduces the same typographic HTML.
 *
 * Verbatim contexts (inline code, fenced code) are excluded: the parser never
 * normalizes there, so a typographic character inside code is genuine literal
 * content and must survive untouched.
 */
class HtmlToDjotSmartPunctuationTest extends TestCase
{
    protected HtmlToDjot $converter;

    protected DjotConverter $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new HtmlToDjot();
        $this->renderer = new DjotConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reversibleProvider(): array
    {
        return [
            'ellipsis' => ['<p>a … b</p>', 'a ... b'],
            'en dash' => ['<p>a – b</p>', 'a -- b'],
            'em dash' => ['<p>a — b</p>', 'a --- b'],
            'ellipsis no spaces' => ['<p>wait…done</p>', 'wait...done'],
            'em dash no spaces' => ['<p>x—y</p>', 'x---y'],
        ];
    }

    #[DataProvider('reversibleProvider')]
    public function testTypographicPunctuationReversedToAscii(string $html, string $expectedFragment): void
    {
        $djot = $this->converter->convert($html);

        $this->assertStringContainsString($expectedFragment, $djot);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceStableProvider(): array
    {
        return [
            'ellipsis' => ['a ... b'],
            'en dash' => ['a -- b'],
            'em dash' => ['a --- b'],
            'mixed' => ['well ... maybe --- or -- so'],
        ];
    }

    /**
     * The original ASCII source survives a full Djot -> HTML -> Djot round-trip.
     */
    #[DataProvider('sourceStableProvider')]
    public function testRoundTripPreservesAsciiSource(string $djotSource): void
    {
        $html = $this->renderer->convert($djotSource);
        $back = trim($this->converter->convert($html));

        $this->assertSame($djotSource, $back);
    }

    /**
     * A dash at the start of a line must not be reversed: `---`/`--` there would
     * re-parse as a thematic break or interact with the leading-marker escaper.
     * The typographic dash stays literal, which remains HTML-stable.
     */
    public function testLeadingEmDashStaysLiteral(): void
    {
        $djot = trim($this->converter->convert('<p>— quote</p>'));

        $this->assertSame('— quote', $djot);

        $reRendered = $this->renderer->convert($djot);
        $this->assertStringNotContainsString('<hr', $reRendered);
        $this->assertStringContainsString('quote', $reRendered);
    }

    public function testLeadingEnDashStaysLiteral(): void
    {
        $djot = trim($this->converter->convert('<p>– quote</p>'));

        $this->assertSame('– quote', $djot);

        $reRendered = $this->renderer->convert($djot);
        $this->assertStringNotContainsString('<hr', $reRendered);
    }

    /**
     * A leading ellipsis is harmless (not a block marker) and is reversed.
     */
    public function testLeadingEllipsisReversed(): void
    {
        $djot = trim($this->converter->convert('<p>… and so on</p>'));

        $this->assertSame('... and so on', $djot);
    }

    /**
     * Inline code is verbatim: a typographic character there is literal content
     * and must not be rewritten to ASCII.
     */
    public function testTypographicPunctuationInInlineCodePreserved(): void
    {
        $djot = trim($this->converter->convert('<p><code>x … y — z</code></p>'));

        $this->assertSame('`x … y — z`', $djot);
    }

    /**
     * Fenced code is verbatim for the same reason.
     */
    public function testTypographicPunctuationInFencedCodePreserved(): void
    {
        $djot = $this->converter->convert('<pre><code>a — b … c</code></pre>');

        $this->assertStringContainsString('a — b … c', $djot);
    }
}
