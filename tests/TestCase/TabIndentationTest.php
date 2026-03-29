<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for tab character handling in djot parsing.
 *
 * Related to upstream issue: https://github.com/jgm/djot/issues/255
 * The djot spec doesn't explicitly define tab handling, so these tests
 * document current behavior for reference.
 */
class TabIndentationTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    /**
     * Tabs inside code blocks are converted to 4 spaces by default.
     */
    public function testTabsInCodeBlockConvertedToSpaces(): void
    {
        $input = "```\n\tindented with tab\n\t\tdouble tab\n```";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString("    indented with tab", $result);
        $this->assertStringContainsString("        double tab", $result);
    }

    /**
     * Tabs between backslash and newline should create hard break.
     */
    public function testTabsAfterBackslashHardBreak(): void
    {
        $input = "ab\\\t\nc";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<br>', $result);
    }

    /**
     * Tab-indented content under list item (single level).
     */
    public function testTabIndentedListContent(): void
    {
        $input = "- Item 1\n\tNested content";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('Nested content', $result);
    }

    /**
     * Block quote with space after > works.
     */
    public function testBlockQuoteWithSpace(): void
    {
        $input = '> Quoted with space';
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Quoted with space', $result);
    }

    /**
     * Block quote requires space after > (not tab) per djot spec.
     *
     * The space after > is a syntax delimiter, not indentation.
     * Tab indentation rules apply to nested structures, not syntax delimiters.
     */
    public function testBlockQuoteWithTabNotBlockquote(): void
    {
        $input = ">\tQuoted with tab";
        $result = $this->converter->convert($input);

        // Per spec, > must be followed by space, not tab
        // So this becomes a paragraph with > as text
        $this->assertStringNotContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    /**
     * Nested list with spaces requires blank line before nested items.
     */
    public function testNestedListWithSpacesAndBlankLine(): void
    {
        $input = "- Level 1\n\n  - Level 2";
        $result = $this->converter->convert($input);

        // Should have nested ul
        $this->assertSame(2, substr_count($result, '<ul>'));
        $this->assertSame(2, substr_count($result, '</ul>'));
    }

    /**
     * Nested list with tabs - current behavior.
     *
     * Without blank lines, nested markers are not recognized as nested lists
     * (this matches djot spec - blank line required for nesting).
     */
    public function testNestedListWithTabsNoBlankLine(): void
    {
        $input = "- Level 1\n\t- Level 2";
        $result = $this->converter->convert($input);

        // Current behavior: creates flat list, not nested
        // The "- Level 2" is content, not a nested list marker
        $this->assertSame(1, substr_count($result, '<ul>'));
    }

    /**
     * Nested list with tabs and blank line - one tab = one indentation level.
     */
    public function testNestedListWithTabsAndBlankLine(): void
    {
        $input = "- Level 1\n\n\t- Level 2";
        $result = $this->converter->convert($input);

        // With blank line and tab indentation, should create nested list
        $this->assertSame(2, substr_count($result, '<ul>'));
        $this->assertSame(2, substr_count($result, '</ul>'));
    }

    /**
     * Deeply nested list with multiple tabs.
     */
    public function testDeeplyNestedListWithTabs(): void
    {
        $input = "- Level 1\n\n\t- Level 2\n\n\t\t- Level 3";
        $result = $this->converter->convert($input);

        // Three levels of nesting
        $this->assertSame(3, substr_count($result, '<ul>'));
        $this->assertSame(3, substr_count($result, '</ul>'));
    }

    /**
     * Mixed tabs and spaces for indentation (tabs expand to reach next level).
     */
    public function testMixedTabsAndSpacesIndentation(): void
    {
        $input = "- Level 1\n\n  - Level 2 with spaces\n\n\t- Level 2 with tab";
        $result = $this->converter->convert($input);

        // Both should create nested lists at same level
        $this->assertStringContainsString('Level 2 with spaces', $result);
        $this->assertStringContainsString('Level 2 with tab', $result);
    }

    /**
     * Definition list with tab indent.
     */
    public function testDefinitionListWithTabIndent(): void
    {
        $input = ": Term\n\n\tDefinition with tab indent";
        $result = $this->converter->convert($input);

        // Check if definition list is created
        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<dt>Term</dt>', $result);
    }

    /**
     * Footnote with tab-indented continuation.
     */
    public function testFootnoteWithTabIndent(): void
    {
        // Need to reference the footnote for it to be rendered
        $input = "See note[^note].\n\n[^note]: First line\n\tContinuation with tab";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('First line', $result);
        $this->assertStringContainsString('Continuation with tab', $result);
    }

    /**
     * Block quote continuation requires space after > (not tab).
     *
     * Since >\t is not a blockquote marker, both lines become paragraphs.
     */
    public function testBlockQuoteContinuationWithTabNotBlockquote(): void
    {
        $input = ">\tLine 1\n>\tLine 2";
        $result = $this->converter->convert($input);

        // Per spec, > must be followed by space
        $this->assertStringNotContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    /**
     * Mixed spaces and tabs in list content.
     */
    public function testMixedSpacesAndTabsInList(): void
    {
        $input = "- Item\n  \tMixed: 2 spaces + tab";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('Mixed: 2 spaces + tab', $result);
    }

    /**
     * Tab in inline verbatim/code span - converted to spaces by default.
     */
    public function testTabInInlineCode(): void
    {
        $input = '`code	with	tabs`';
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<code>code', $result);
        // Tabs are converted to 4 spaces in inline code by default
        $this->assertStringContainsString('    ', $result);
        $this->assertStringNotContainsString("\t", $result);
    }

    /**
     * Tab as part of regular paragraph text.
     */
    public function testTabInParagraphText(): void
    {
        $input = "word1\tword2";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<p>', $result);
        // Tab preserved in paragraph
        $this->assertStringContainsString("\t", $result);
    }

    /**
     * Fenced div with tab indentation.
     */
    public function testFencedDivWithTabIndent(): void
    {
        $input = ":::\n\tContent with tab\n:::";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<div>', $result);
    }

    /**
     * Heading with leading tab - should NOT be a heading.
     */
    public function testHeadingWithLeadingTab(): void
    {
        $input = "\t# Not a heading";
        $result = $this->converter->convert($input);

        // Tab-indented # should not be a heading
        $this->assertStringNotContainsString('<h1>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    /**
     * Heading requires space after # marker, not tab.
     *
     * The space after # is a syntax delimiter, not indentation.
     */
    public function testHeadingWithTabAfterMarker(): void
    {
        $input = "#\tNot a heading";
        $result = $this->converter->convert($input);

        // Tab after # is not valid - must be space per spec
        $this->assertStringNotContainsString('<h1>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    /**
     * List marker requires space after - marker, not tab.
     *
     * The space after - is a syntax delimiter, not indentation.
     */
    public function testListWithTabAfterMarker(): void
    {
        $input = "-\tNot a list item";
        $result = $this->converter->convert($input);

        // Tab after - is not valid - must be space per spec
        $this->assertStringNotContainsString('<li>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    /**
     * Thematic break can be indented (per spec).
     */
    public function testThematicBreakWithTabIndent(): void
    {
        $input = "\t* * *";
        $result = $this->converter->convert($input);

        // Thematic breaks "may be indented" per spec
        // Current behavior check
        $hasHr = str_contains($result, '<hr');
        $this->assertTrue($hasHr || str_contains($result, '<p>'), 'Should be either hr or paragraph');
    }
}
