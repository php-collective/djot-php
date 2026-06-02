<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip safety for HTML that originates outside Djot (WYSIWYG editors, CMS
 * imports, pasted content).
 *
 * Such HTML carries literal text that happens to contain Djot-significant
 * characters (`*`, `_`, `[`, leading `-`, ...). When HtmlToDjot emits that text
 * verbatim, the next Djot parse re-interprets the characters as markup and the
 * meaning of the document changes (a literal `*b*` becomes <strong>, a literal
 * leading `-` becomes a list item).
 *
 * The invariant under test: rendering the produced Djot back to HTML must
 * reproduce the original HTML. Characters that were literal text stay literal
 * text.
 */
class HtmlToDjotEscapeTest extends TestCase
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
     * @return array<string, array{string}>
     */
    public static function literalTextProvider(): array
    {
        return [
            // Inline emphasis/strong markers.
            'asterisk emphasis' => ['<p>a *b* c</p>'],
            'underscore emphasis' => ['<p>a _b_ c</p>'],
            // Inline code span.
            'inline backticks' => ['<p>use a `code` here</p>'],
            // Link / span brackets.
            'link brackets' => ['<p>see [text](url) below</p>'],
            'reference brackets' => ['<p>see [text][ref] below</p>'],
            // Inline span / attribute braces.
            'attribute braces' => ['<p>value {key=val} literal</p>'],
            // Autolink angle brackets.
            'autolink angles' => ['<p>visit &lt;http://x.test&gt; now</p>'],
            // Sub/superscript markers.
            'tilde subscript' => ['<p>H~2~O formula</p>'],
            'caret superscript' => ['<p>x^2^ value</p>'],
            // Block markers at line start.
            'leading dash' => ['<p>- not a list item</p>'],
            'leading plus' => ['<p>+ not a list item</p>'],
            'leading asterisk' => ['<p>* not a list item</p>'],
            'leading hash' => ['<p># not a heading</p>'],
            'ordered dot' => ['<p>1. not an ordered list</p>'],
            'ordered paren' => ['<p>1) not an ordered list</p>'],
            'blockquote marker' => ['<p>&gt; not a quote</p>'],
            // Escape character itself.
            'literal backslash' => ['<p>a \\ b</p>'],
        ];
    }

    #[DataProvider('literalTextProvider')]
    public function testLiteralTextSurvivesRoundTrip(string $html): void
    {
        $djot = $this->converter->convert($html);
        $reRendered = trim($this->renderer->convert($djot));

        $this->assertSame(
            $html,
            $reRendered,
            "Literal text changed meaning on round-trip.\nDjot intermediate: " . $djot,
        );
    }

    /**
     * Intraword underscores (`snake_case`) are literal in Djot and must not be
     * escaped: emitting `snake\_case` is correct but noisy, and import output is
     * full of identifiers, filenames and URLs that would otherwise be peppered
     * with backslashes. Asterisks get no such exemption (Djot emphasis works
     * intraword), nor do sub/superscript markers (`H~2~O` is a real subscript).
     */
    public function testIntrawordUnderscoreIsNotEscaped(): void
    {
        $this->assertSame(
            'use snake_case and SCREAMING_CASE here',
            trim($this->converter->convert('<p>use snake_case and SCREAMING_CASE here</p>')),
        );
    }

    public function testBoundaryUnderscoreStillEscaped(): void
    {
        // Round-trip safety still holds for emphasis-capable underscores.
        $html = '<p>a _b_ c</p>';
        $this->assertSame($html, trim($this->renderer->convert($this->converter->convert($html))));
    }

    /**
     * Pasted HTML often opens a paragraph with an inline wrapper such as
     * `<span>- x</span>`. The wrapper itself carries no Djot syntax, so the
     * marker is still at column zero and must be escaped; otherwise the
     * paragraph re-parses as a list or heading.
     *
     * @return array<string, array{string, string}>
     */
    public static function wrappedLeadingMarkerProvider(): array
    {
        return [
            'span dash' => ['<p><span>- x</span></p>', '<ul'],
            'span hash' => ['<p><span># x</span></p>', '<h1'],
            'em then span dash' => ['<p><em>a</em> <span>- x</span></p>', '<ul'],
        ];
    }

    #[DataProvider('wrappedLeadingMarkerProvider')]
    public function testWrappedLeadingMarkerStaysInline(string $html, string $forbiddenTag): void
    {
        $djot = $this->converter->convert($html);
        $reRendered = $this->renderer->convert($djot);

        // The wrapper element may be dropped (a bare span has no Djot form), but
        // the block structure must not change.
        $this->assertStringNotContainsString($forbiddenTag, $reRendered);
        $this->assertStringContainsString('<p>', $reRendered);
    }

    /**
     * A leading run of dashes must not be re-parsed as a thematic break (the
     * structural break). Typographic normalization of the remaining dashes
     * (smart en/em dashes) is acceptable and intentional, like smart quotes.
     */
    public function testLeadingDashesDoNotBecomeThematicBreak(): void
    {
        $djot = $this->converter->convert('<p>--- not a break</p>');
        $reRendered = $this->renderer->convert($djot);

        $this->assertStringNotContainsString('<hr', $reRendered);
        $this->assertStringContainsString('not a break', $reRendered);
    }
}
