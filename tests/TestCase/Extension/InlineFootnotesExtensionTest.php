<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\InlineFootnotesExtension;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Djot\Extension\InlineFootnotesExtension
 */
class InlineFootnotesExtensionTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new DjotConverter();
        $this->converter->addExtension(new InlineFootnotesExtension());
    }

    public function testBasicInlineFootnote(): void
    {
        $djot = 'Some text[This is an inline footnote]{.fn} continues here.';
        $html = $this->converter->convert($djot);

        // Check for footnote reference in text
        $this->assertStringContainsString('fnref1', $html);
        $this->assertStringContainsString('href="#fn1"', $html);
        $this->assertStringContainsString('<sup class="footnote-ref">', $html);

        // Check for footnote content in endnotes section
        $this->assertStringContainsString('id="fn1"', $html);
        $this->assertStringContainsString('This is an inline footnote', $html);
        $this->assertStringContainsString('role="doc-endnotes"', $html);
    }

    public function testMultipleInlineFootnotes(): void
    {
        $djot = 'First[Note one]{.fn} and second[Note two]{.fn} footnotes.';
        $html = $this->converter->convert($djot);

        // Check both references exist
        $this->assertStringContainsString('fnref1', $html);
        $this->assertStringContainsString('fnref2', $html);

        // Check both contents exist
        $this->assertStringContainsString('Note one', $html);
        $this->assertStringContainsString('Note two', $html);

        // Verify ordering - fn1 should come before fn2 in the endnotes
        $fn1Pos = strpos($html, 'id="fn1"');
        $fn2Pos = strpos($html, 'id="fn2"');
        $this->assertGreaterThan($fn1Pos, $fn2Pos);
    }

    public function testInlineFootnoteWithFormatting(): void
    {
        $djot = 'Text[A footnote with _emphasis_ and `code`]{.fn} here.';
        $html = $this->converter->convert($djot);

        // Check formatting is preserved (djot: _ = emphasis, * = strong)
        $this->assertStringContainsString('<em>emphasis</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function testMixedInlineAndRegularFootnotes(): void
    {
        $djot = <<<'DJOT'
Text with inline[Inline content]{.fn} and regular[^ref] footnotes.

[^ref]: Regular footnote content.
DJOT;
        $html = $this->converter->convert($djot);

        // Both footnotes should be present
        $this->assertStringContainsString('Inline content', $html);
        $this->assertStringContainsString('Regular footnote content', $html);

        // Should have fn1 and fn2
        $this->assertStringContainsString('id="fn1"', $html);
        $this->assertStringContainsString('id="fn2"', $html);
    }

    public function testInlineFootnoteAfterRegularFootnote(): void
    {
        $djot = <<<'DJOT'
Regular first[^a] then inline[Second note]{.fn} here.

[^a]: First note content.
DJOT;
        $html = $this->converter->convert($djot);

        // Regular footnote should be fn1 (referenced first)
        $this->assertStringContainsString('First note content', $html);
        $this->assertStringContainsString('Second note', $html);

        // Both should appear in footnotes section
        $this->assertStringContainsString('role="doc-endnotes"', $html);
    }

    public function testRegularSpanNotAffected(): void
    {
        $djot = 'This [span]{.highlight} should not be a footnote.';
        $html = $this->converter->convert($djot);

        // Should NOT have footnote markup
        $this->assertStringNotContainsString('fnref', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);

        // Should render as regular span
        $this->assertStringContainsString('<span class="highlight">span</span>', $html);
    }

    public function testSpanWithMultipleClassesIncludingFn(): void
    {
        $djot = 'Text[Note with extra classes]{.fn .important} here.';
        $html = $this->converter->convert($djot);

        // Should be treated as footnote due to .fn class
        $this->assertStringContainsString('fnref1', $html);
        $this->assertStringContainsString('Note with extra classes', $html);
    }

    public function testEmptyInlineFootnote(): void
    {
        $djot = 'Text[]{.fn} continues.';
        $html = $this->converter->convert($djot);

        // Should still create footnote reference
        $this->assertStringContainsString('fnref1', $html);
        // Footnote section should exist
        $this->assertStringContainsString('role="doc-endnotes"', $html);
    }

    public function testBacklinkGeneration(): void
    {
        $djot = 'Text[An inline footnote]{.fn} here.';
        $html = $this->converter->convert($djot);

        // Check for backlink
        $this->assertStringContainsString('href="#fnref1"', $html);
        $this->assertStringContainsString('role="doc-backlink"', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new InlineFootnotesExtension(cssClass: 'footnote'));

        $djot = 'Text[Custom class]{.footnote} here.';
        $html = $converter->convert($djot);

        $this->assertStringContainsString('fnref1', $html);
        $this->assertStringContainsString('Custom class', $html);
    }

    public function testNestedFormattingInFootnote(): void
    {
        $djot = 'Text[A note with [a link](https://example.com)]{.fn} here.';
        $html = $this->converter->convert($djot);

        // Link should be preserved in footnote
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testFootnoteNumberingAcrossRenders(): void
    {
        // First render
        $html1 = $this->converter->convert('First[Note A]{.fn} doc.');

        // Second render should reset numbering
        $html2 = $this->converter->convert('Second[Note B]{.fn} doc.');

        // Both should have fnref1 (not fnref2 in second)
        $this->assertStringContainsString('fnref1', $html1);
        $this->assertStringContainsString('fnref1', $html2);
        $this->assertStringNotContainsString('fnref2', $html2);
    }

    public function testInlineFootnoteWithNestedSpan(): void
    {
        $djot = 'Text[Note with [nested span]{.nested}]{.fn} here.';
        $html = $this->converter->convert($djot);

        // Should handle nested span correctly
        $this->assertStringContainsString('fnref1', $html);
        $this->assertStringContainsString('class="nested"', $html);
    }
}
