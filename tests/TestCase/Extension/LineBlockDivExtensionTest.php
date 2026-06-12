<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\LineBlockDivExtension;
use PHPUnit\Framework\TestCase;

class LineBlockDivExtensionTest extends TestCase
{
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

    public function testLeadingWhitespaceIsPreserved(): void
    {
        $djot = "::: |\nFlush left\n  Indented two\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString("Flush left<br>\n  Indented two", $html);
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
        $this->assertStringContainsString("Roses are red<br>\n  Violets are blue", $html);
    }

    public function testWorksInsideListItem(): void
    {
        $djot = "- item\n\n  ::: |\n  Line one\n    Indented two\n  :::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("Line one<br>\n  Indented two", $html);
    }

    public function testWorksInsideBlockquotedList(): void
    {
        $djot = "> - x\n>\n>   ::: |\n>   alpha\n>     beta\n>   :::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("alpha<br>\n  beta", $html);
    }

    public function testPlainDivWithRealClassIsUntouched(): void
    {
        $djot = "::: warning\nHello\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<div class="warning">', $html);
        $this->assertStringNotContainsString('line-block', $html);
    }
}
