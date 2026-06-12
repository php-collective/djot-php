<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\LineBlockDivExtension;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class LineBlockDivExtensionTest extends TestCase
{
    /**
     * @var string
     */
    private const NBSP = "\u{00A0}";

    /**
     * Internal non-breaking-space placeholder (private use area).
     *
     * @var string
     */
    private const NBSP_PLACEHOLDER = "\u{E000}";

    private function converter(): DjotConverter
    {
        $converter = new DjotConverter();
        $converter->addExtension(new LineBlockDivExtension());

        return $converter;
    }

    public function testPipeMarkerBecomesLineBlockDiv(): void
    {
        $djot = "::: |\nLine one\nLine two\n:::";

        $html = $this->converter()->convert($djot);

        // The pipe marker is consumed: a `line-block` div, never `class="|"`.
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringNotContainsString('class="|"', $html);
    }

    public function testSoftBreaksBecomeHardBreaks(): void
    {
        $djot = "::: |\nLine one\nLine two\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString("Line one<br>\nLine two", $html);
    }

    public function testLeadingWhitespaceIsPreservedAsNonBreakingSpaces(): void
    {
        $djot = "::: |\nFlush left\n  Indented two\n:::";

        $html = $this->converter()->convert($djot);

        // Leading spaces become non-breaking spaces so the indent survives the
        // browser's whitespace collapsing.
        $this->assertStringContainsString("Flush left<br>\n&nbsp;&nbsp;Indented two", $html);
    }

    public function testNonHtmlOutputUsesRegularSpaces(): void
    {
        // The nbsp placeholder is an HTML-only concern; plain text gets ordinary
        // spaces, so the indentation is still visible without an invisible
        // placeholder leaking into the output.
        $document = $this->converter()->parse("::: |\nLine one\n  Indented two\n:::");
        $text = (new PlainTextRenderer())->render($document);

        $this->assertStringContainsString("Line one\n  Indented two", $text);
        $this->assertStringNotContainsString(self::NBSP_PLACEHOLDER, $text);
    }

    public function testMarkdownPreservesIndentedFirstLineAsNonBreakingSpaces(): void
    {
        // Markdown is a re-parseable round-trip format, so the indent is kept as
        // real non-breaking spaces (U+00A0): they survive trimming, survive a
        // re-render as &nbsp;, and are never mistaken for an indented code block.
        $document = $this->converter()->parse("::: |\n  first\n  second\n:::");
        $markdown = (new MarkdownRenderer())->render($document);
        $firstLine = explode("\n", $markdown)[0];

        $this->assertSame(self::NBSP . self::NBSP . 'first', rtrim($firstLine));
        $this->assertStringNotContainsString(self::NBSP_PLACEHOLDER, $markdown);
    }

    public function testLiteralNonBreakingSpaceInContentIsPreserved(): void
    {
        // A real U+00A0 the author typed in the verse must survive in plain text
        // (the placeholder uses a private-use char, so it is not clobbered).
        $document = $this->converter()->parse("::: |\nice" . self::NBSP . "cream\n:::");
        $text = (new PlainTextRenderer())->render($document);

        $this->assertStringContainsString('ice' . self::NBSP . 'cream', $text);
    }

    public function testTabIndentExpandsToFourColumns(): void
    {
        $djot = "::: |\nflush\n\ttabbed\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString("flush<br>\n" . str_repeat('&nbsp;', 4) . 'tabbed', $html);
    }

    public function testBlankLineSeparatesStanzas(): void
    {
        $djot = "::: |\nStanza one a\nStanza one b\n\nStanza two a\nStanza two b\n:::";

        $html = $this->converter()->convert($djot);

        // Two paragraphs inside a single line-block div.
        $this->assertSame(2, substr_count($html, '<p>'));
        $this->assertStringContainsString("Stanza one a<br>\nStanza one b", $html);
        $this->assertStringContainsString("Stanza two a<br>\nStanza two b", $html);
    }

    public function testInlineMarkupStillParses(): void
    {
        $djot = "::: |\nA _em_ and a [link](https://example.com)\nplain\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<em>em</em>', $html);
        $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
    }

    public function testPendingAttributesAttachToTheDiv(): void
    {
        $djot = "{#poem .verse}\n::: |\nLine one\nLine two\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('id="poem"', $html);
        $this->assertStringContainsString('verse', $html);
        $this->assertStringContainsString('line-block', $html);
    }

    public function testFencedCodeInsideIsNotTreatedAsClosingFence(): void
    {
        $djot = "::: |\nbefore\n```\n:::\nstill code\n```\nafter\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString('after', $html);
        // The ::: inside the code fence did not close the line block.
        $this->assertStringContainsString('still code', $html);
    }

    public function testInfoStringCodeFenceInsideIsNotAClosingFence(): void
    {
        // An info-string code fence (``` djot) opens a code block; the `:::`
        // inside it must not close the line block (matches the core div parser).
        $djot = "::: |\nbefore\n``` djot\n:::\n```\nafter\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString('after', $html);
    }

    public function testLongerOpenerFenceRequiresAtLeastAsLongCloser(): void
    {
        $djot = ":::: |\nLine one\n:::\nstill inside\n::::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<div class="line-block">', $html);
        // The shorter ::: does not close a :::: opener.
        $this->assertStringContainsString('still inside', $html);
    }

    public function testUnclosedFenceFallsThroughToCore(): void
    {
        $djot = "::: |\nLine one\nLine two";

        $html = $this->converter()->convert($djot);

        // No closer: not a line block. Core handles it as an ordinary div, so the
        // extension must not have produced a line-block div.
        $this->assertStringNotContainsString('class="line-block"', $html);
    }

    public function testWorksInsideBlockquote(): void
    {
        $djot = "> ::: |\n> Roses are red\n>   Violets are blue\n> :::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<div class="line-block">', $html);
        // Indentation relative to the line block survives the blockquote dedent.
        $this->assertStringContainsString("Roses are red<br>\n&nbsp;&nbsp;Violets are blue", $html);
    }

    public function testWorksInsideListItem(): void
    {
        $djot = "- item\n\n  ::: |\n  Line one\n    Indented two\n  :::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("Line one<br>\n&nbsp;&nbsp;Indented two", $html);
    }

    public function testWorksInsideBlockquotedList(): void
    {
        $djot = "> - x\n>\n>   ::: |\n>   alpha\n>     beta\n>   :::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("alpha<br>\n&nbsp;&nbsp;beta", $html);
    }

    public function testMedialGapIsPreservedAsNonBreakingSpaces(): void
    {
        // A line block keeps medial alignment, not only the leading indent: the
        // caesura of Old English verse is a run of spaces in the middle of a line.
        $djot = "::: |\nHwaet! We Gardena    in geardagum\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('Hwaet! We Gardena' . str_repeat('&nbsp;', 4) . 'in geardagum', $html);
    }

    public function testSingleInnerSpaceStaysOrdinary(): void
    {
        // Ordinary word spacing must stay a real, collapsible space so the line
        // can still wrap; only runs of two or more columns are treated as a gap.
        $djot = "::: |\nThe limerick packs laughs\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('The limerick packs laughs', $html);
        $this->assertStringNotContainsString('&nbsp;', $html);
    }

    public function testMedialGapNonHtmlUsesRegularSpaces(): void
    {
        // Plain text keeps the gap visible as ordinary spaces, with no placeholder
        // leaking through.
        $document = $this->converter()->parse("::: |\nleft    right\n:::");
        $text = (new PlainTextRenderer())->render($document);

        $this->assertStringContainsString('left    right', $text);
        $this->assertStringNotContainsString(self::NBSP_PLACEHOLDER, $text);
    }

    public function testMedialGapMarkdownUsesNonBreakingSpaces(): void
    {
        // Markdown round-trips the gap as real U+00A0 so re-rendering keeps it.
        $document = $this->converter()->parse("::: |\nleft  right\n:::");
        $markdown = (new MarkdownRenderer())->render($document);

        $this->assertStringContainsString('left' . self::NBSP . self::NBSP . 'right', $markdown);
        $this->assertStringNotContainsString(self::NBSP_PLACEHOLDER, $markdown);
    }

    public function testMedialTabExpandsToColumnStop(): void
    {
        // A tab used as a medial gap expands to the next four-column stop.
        $djot = "::: |\nab\tcd\n:::";

        $html = $this->converter()->convert($djot);

        // "ab" sits at columns 0-1; the tab fills columns 2-3 -> two nbsp.
        $this->assertStringContainsString('ab' . str_repeat('&nbsp;', 2) . 'cd', $html);
    }

    public function testMedialGapKeepsInlineMarkup(): void
    {
        // Inline markup on either side of a gap still parses.
        $djot = "::: |\n_em_    [link](https://example.com)\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<em>em</em>' . str_repeat('&nbsp;', 4) . '<a href="https://example.com">link</a>', $html);
    }

    public function testPlainDivWithRealClassIsUntouched(): void
    {
        $djot = "::: warning\nHello\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<div class="warning">', $html);
        $this->assertStringNotContainsString('line-block', $html);
    }
}
