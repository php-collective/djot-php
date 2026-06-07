<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * A list item's lazy text continuation strips all leading whitespace, matching
 * the reference implementation. Indentation beyond the content column is not
 * significant for plain text, so no residual spaces or tabs leak into the output.
 *
 * Properly nested blocks (separated by a blank line) keep their relative
 * indentation, which is handled by a different code path.
 */
class ListItemContinuationIndentTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->converter->getHtmlRenderer()->setSoftBreakMode(SoftBreakMode::Newline);
    }

    public function testOverIndentedSpacesAreStripped(): void
    {
        $html = $this->converter->convert("- a\n      deep");
        $this->assertStringContainsString("a\ndeep", $html);
        $this->assertStringNotContainsString('    deep', $html);
    }

    public function testOverIndentedTabsAreStripped(): void
    {
        $html = $this->converter->convert("- a\n\t\tdeep");
        $this->assertStringContainsString("a\ndeep", $html);
        $this->assertStringNotContainsString("\tdeep", $html);
    }

    public function testContentIndentContinuationStillWorks(): void
    {
        $html = $this->converter->convert("- a\n  more");
        $this->assertStringContainsString("a\nmore", $html);
    }

    public function testNestedCodeBlockKeepsIndentation(): void
    {
        // Properly nested (blank line) code block keeps its internal indentation.
        $html = $this->converter->convert("- item\n\n  ```\n  def f():\n      return 1\n  ```");
        $this->assertStringContainsString("def f():\n    return 1", $html);
    }
}
