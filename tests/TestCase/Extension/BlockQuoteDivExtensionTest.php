<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\BlockQuoteDivExtension;
use PHPUnit\Framework\TestCase;

class BlockQuoteDivExtensionTest extends TestCase
{
    private function converter(): DjotConverter
    {
        $converter = new DjotConverter();
        $converter->addExtension(new BlockQuoteDivExtension());

        return $converter;
    }

    public function testGtMarkerBecomesBlockquote(): void
    {
        $djot = "::: >\nA quote.\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<p>A quote.</p>', $html);
        $this->assertStringNotContainsString('class=">"', $html);
        $this->assertStringNotContainsString('<div', $html);
    }

    public function testOwnsBlockContentWithoutPerLineMarker(): void
    {
        $djot = "::: >\n- item one\n- item two\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertMatchesRegularExpression('/<li>\s*item one\s*<\/li>/', $html);
        $this->assertMatchesRegularExpression('/<li>\s*item two\s*<\/li>/', $html);
    }

    public function testEquivalentToPrefixedBlockquote(): void
    {
        $fenced = $this->converter()->convert("::: >\n- a\n- b\n:::");
        $prefixed = $this->converter()->convert("> - a\n> - b");

        $this->assertSame($prefixed, $fenced);
    }

    public function testCaptionAfterCloseWrapsInFigure(): void
    {
        $djot = "::: >\nStay hungry, stay foolish.\n:::\n^ Steve Jobs";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('Steve Jobs', $html);
        $this->assertStringContainsString('<figcaption>', $html);
    }

    public function testAttributesOnPrecedingLineLandOnBlockquote(): void
    {
        $djot = "{#epigraph .pull}\n::: >\nText.\n:::";

        $html = $this->converter()->convert($djot);

        // Attributes must land on the blockquote itself, not on the inner block.
        $this->assertMatchesRegularExpression('/<blockquote[^>]*id="epigraph"/', $html);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*class="pull"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*id="epigraph"/', $html);
    }

    public function testAttributesTransferToFigureWithCaption(): void
    {
        $djot = "{#epigraph .pull}\n::: >\nText.\n:::\n^ Author";

        $html = $this->converter()->convert($djot);

        // Caption wrapping moves the blockquote's attributes onto the figure.
        $this->assertMatchesRegularExpression('/<figure[^>]*id="epigraph"/', $html);
        $this->assertMatchesRegularExpression('/<figure[^>]*class="pull"/', $html);
        $this->assertStringContainsString('<figcaption>', $html);
    }

    public function testNestingWithLongerFence(): void
    {
        $djot = ":::: >\nOuter.\n\n::: >\nInner.\n:::\n::::";

        $html = $this->converter()->convert($djot);

        $this->assertSame(2, substr_count($html, '<blockquote>'));
        $this->assertStringContainsString('Outer.', $html);
        $this->assertStringContainsString('Inner.', $html);
    }

    public function testInnerCodeFenceIsNotMistakenForTheCloser(): void
    {
        // The ::: inside the code block must not close the quote; only the
        // bare ::: after the code fence does.
        $djot = "::: >\n```\n:::\n```\nAfter.\n:::";

        $html = $this->converter()->convert($djot);

        $this->assertStringContainsString('<blockquote>', $html);
        // The code block keeps the literal ::: as its content.
        $this->assertMatchesRegularExpression('/<code[^>]*>[\s\S]*:::[\s\S]*<\/code>/', $html);
        $this->assertStringContainsString('After.', $html);
    }

    public function testUnclosedFenceDoesNotProduceBlockquote(): void
    {
        $djot = "::: >\nNo closer here.";

        $html = $this->converter()->convert($djot);

        $this->assertStringNotContainsString('<blockquote>', $html);
    }

    public function testWithoutExtensionGtDivHasNoBlockquote(): void
    {
        // Sanity: the slot is only claimed when the extension is registered.
        $plain = (new DjotConverter())->convert("::: >\nText.\n:::");

        $this->assertStringNotContainsString('<blockquote>', $plain);
    }
}
