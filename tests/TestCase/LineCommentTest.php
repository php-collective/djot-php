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

    /**
     * %% inside code spans should NOT be treated as a comment
     */
    public function testPercentInCodeSpanPreserved(): void
    {
        $djot = '`code %% not a comment`';
        $expected = "<p><code>code %% not a comment</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testPercentInCodeSpanMidText(): void
    {
        $djot = 'Before `a %% b` after';
        $expected = "<p>Before <code>a %% b</code> after</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * %% inside quoted attribute values should NOT be treated as a comment
     */
    public function testPercentInQuotedAttributePreserved(): void
    {
        $djot = '[text]{title="%% not a comment"}';
        $expected = "<p><span title=\"%% not a comment\">text</span></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testPercentInSingleQuotedAttributePreserved(): void
    {
        $djot = "[text]{title='%% test'}";
        $expected = "<p><span title=\"%% test\">text</span></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * %% inside link URLs should NOT be treated as a comment
     */
    public function testPercentInLinkUrlPreserved(): void
    {
        $djot = '[link](url%%test)';
        $expected = "<p><a href=\"url%%test\">link</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * Escaped %% should NOT be treated as a comment
     */
    public function testEscapedPercentNotComment(): void
    {
        $djot = 'Text \\%\\% not a comment';
        $expected = "<p>Text %% not a comment</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * %% inside inline math should NOT be treated as a comment
     */
    public function testPercentInMathPreserved(): void
    {
        $djot = '$x %% y$';
        $result = $this->converter->convert($djot);
        // Math content should be preserved (whether parsed as math or not)
        $this->assertStringContainsString('%%', $result);
    }

    /**
     * Line comment should work with both {% %} and %%
     */
    public function testMixedCommentSyntax(): void
    {
        $djot = 'Text {% inline comment %} more %% line comment';
        $expected = "<p>Text  more</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * %% should strip first occurrence to end of line
     */
    public function testMultiplePercentOnLine(): void
    {
        $djot = 'a %% b %% c';
        $expected = "<p>a</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
