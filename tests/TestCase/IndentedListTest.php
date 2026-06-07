<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * A list marker carrying leading indentation at a block boundary starts a list.
 *
 * djot treats indentation as significant only for nesting, so at the top level
 * (or after a blank line) a leading-indented marker is just a list. This matches
 * the reference djot implementation.
 */
class IndentedListTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testSpaceIndentedMarkerStartsList(): void
    {
        foreach (['  - item', '    - item', ' - item'] as $input) {
            $html = $this->converter->convert($input);
            $this->assertStringContainsString("<ul>\n<li>\nitem\n</li>\n</ul>", $html, $input);
        }
    }

    public function testTabIndentedMarkerStartsList(): void
    {
        $html = $this->converter->convert("\t- item");
        $this->assertStringContainsString("<ul>\n<li>\nitem\n</li>\n</ul>", $html);
    }

    public function testOrderedIndentedMarkerStartsList(): void
    {
        $html = $this->converter->convert('  1. item');
        $this->assertStringContainsString("<ol>\n<li>\nitem\n</li>\n</ol>", $html);
    }

    public function testIndentedSiblingsFormOneList(): void
    {
        $html = $this->converter->convert("  - a\n  - b");
        $this->assertStringContainsString("<li>\na\n</li>\n<li>\nb\n</li>", $html);
    }

    public function testBlankThenIndentedMarkerStartsList(): void
    {
        $html = $this->converter->convert("text\n\n  - item");
        $this->assertStringContainsString('<p>text</p>', $html);
        $this->assertStringContainsString("<ul>\n<li>\nitem", $html);
    }

    /**
     * An indented marker that lazily continues an open paragraph stays text
     * (no blank line), unchanged by this fix.
     */
    public function testIndentedMarkerDoesNotInterruptParagraph(): void
    {
        $html = $this->converter->convert("text\n  - item");
        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringContainsString('text', $html);
    }

    /**
     * An indented marker collected as a list item's continuation stays lazy text
     * in default mode (no blank line), unchanged by this fix.
     */
    public function testNestedMarkerWithoutBlankStaysLazyText(): void
    {
        $html = $this->converter->convert("- a\n  - b");
        $this->assertStringNotContainsString("<ul>\n<li>\na\n<ul>", $html);
    }
}
