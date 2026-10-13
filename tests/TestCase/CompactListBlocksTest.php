<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * Compact list blocks (always on).
 *
 * A blank line is still required to START a block inside a list item (djot's
 * block-recognition and the uniformity principle are unchanged). But that blank
 * line no longer forces the list LOOSE when the indented content opens a block
 * (sub-list, block quote, fenced code, fenced div, heading, table). Only a
 * genuine second prose paragraph, or a blank line between items, makes the list
 * loose. Result: an item can carry a sub-block while staying tight (lead text
 * inline, no <p> ceremony).
 *
 * This is a deliberate divergence from canonical djot, which renders such lists
 * loose; it changes only the tight/loose RENDERING, never the block structure.
 */
class CompactListBlocksTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->converter->getHtmlRenderer()->setSoftBreakMode(SoftBreakMode::Newline);
    }

    public function testBlockquoteAfterBlankStaysTight(): void
    {
        $html = $this->converter->convert("- item\n\n  > note\n- next");
        // Lead text inline (tight), not wrapped in <p>.
        $this->assertStringContainsString("<li>\nitem\n<blockquote>", $html);
        $this->assertStringNotContainsString('<p>item</p>', $html);
    }

    public function testFencedCodeAfterBlankStaysTight(): void
    {
        $html = $this->converter->convert("- run\n\n  ```\n  make\n  ```\n- next");
        $this->assertStringContainsString("<li>\nrun\n<pre>", $html);
        $this->assertStringNotContainsString('<p>run</p>', $html);
    }

    public function testHeadingAfterBlankStaysTight(): void
    {
        $html = $this->converter->convert("- section\n\n  # Title\n- next");
        $this->assertStringContainsString("<li>\nsection\n", $html);
        $this->assertStringNotContainsString('<p>section</p>', $html);
    }

    public function testSublistAfterBlankStaysTight(): void
    {
        $html = $this->converter->convert("- parent\n\n  - child\n- next");
        $this->assertStringNotContainsString('<p>parent</p>', $html);
    }

    public function testSecondProseParagraphStillLoosens(): void
    {
        // A real second paragraph in the item is still a loose list.
        $html = $this->converter->convert("- item\n\n  second paragraph\n- next");
        $this->assertStringContainsString('<p>item</p>', $html);
        $this->assertStringContainsString('<p>second paragraph</p>', $html);
    }

    public function testBlankLineBetweenItemsStillLoosens(): void
    {
        $html = $this->converter->convert("- a\n\n- b");
        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
    }
}
