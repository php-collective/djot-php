<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

class SourceLineTrackingTest extends TestCase
{
    public function testDisabledByDefaultEmitsNoSourceLineAttribute(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("# Heading\n\nParagraph one.\n");

        $this->assertStringNotContainsString('data-source-line', $html);
    }

    public function testEnabledStampsTopLevelBlocksWithOneBasedSourceLine(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        // 1-based lines: 1 "# Heading", 3 "Paragraph one.", 5 "Paragraph two."
        $html = $converter->convert("# Heading\n\nParagraph one.\n\nParagraph two.\n");

        $this->assertStringContainsString('data-source-line="1"', $html);
        $this->assertStringContainsString('data-source-line="3"', $html);
        $this->assertStringContainsString('data-source-line="5"', $html);
    }

    public function testEnabledStampsListAndBlockquote(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        // 1-based: 1 "Intro", 3 "- item a", 4 "- item b", 6 "> quote"
        $html = $converter->convert("Intro\n\n- item a\n- item b\n\n> quote\n");

        // The list block and the blockquote each carry a source line.
        $this->assertMatchesRegularExpression('/<ul[^>]*data-source-line="3"/', $html);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*data-source-line="6"/', $html);
    }

    public function testSourceLineRendersAfterAuthorAttributes(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("{.note}\nParagraph with class.\n");

        // The attribute is appended after author attributes (attribute order).
        $this->assertStringContainsString('<p class="note" data-source-line="2">', $html);
    }
}
