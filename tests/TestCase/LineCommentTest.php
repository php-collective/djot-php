<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for line comment syntax (%% to end of line)
 */
class LineCommentTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testInlineLineComment(): void
    {
        $djot = 'This is visible %% but this is a comment';
        $expected = "<p>This is visible</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testFullLineComment(): void
    {
        $djot = "%% This entire line is a comment\nThis is visible";
        $expected = "<p>This is visible</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testMultipleLineComments(): void
    {
        $djot = "%% These lines are\n%% commented out\nThis line is not";
        $expected = "<p>This line is not</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentWithinParagraph(): void
    {
        $djot = "First line %% comment\nSecond line";
        $expected = "<p>First line\nSecond line</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentDoesNotAffectFencedComment(): void
    {
        // %%% is a fenced comment, not a line comment
        $djot = "%%%\nThis is inside fenced comment\n%%%\n\nParagraph";
        $expected = "<p>Paragraph</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentPreservesTextBefore(): void
    {
        $djot = 'Text before %% comment after';
        $expected = "<p>Text before</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentWithEmphasis(): void
    {
        $djot = '_emphasis_ %% and comment';
        $expected = "<p><em>emphasis</em></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentInBlockQuote(): void
    {
        $djot = '> Quote text %% with comment';
        $expected = "<blockquote>\n<p>Quote text</p>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentInList(): void
    {
        $djot = "- Item one %% comment\n- Item two";
        $expected = "<ul>\n<li>\nItem one\n</li>\n<li>\nItem two\n</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLineCommentInHeading(): void
    {
        $djot = '# Heading %% comment';
        // The section ID is derived from the visible heading text
        $result = $this->converter->convert($djot);
        $this->assertStringContainsString('<h1>Heading</h1>', $result);
        $this->assertStringNotContainsString('comment', $result);
    }
}
