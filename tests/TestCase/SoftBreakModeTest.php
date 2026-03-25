<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for soft break mode configuration.
 *
 * The softBreakMode parameter allows separating parsing behavior
 * (significantNewlines) from rendering behavior (soft breaks as <br>).
 */
class SoftBreakModeTest extends TestCase
{
    /**
     * significantNewlines allows nested lists without blank lines.
     */
    public function testNestedListsWithoutBlankLines(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        $djot = <<<'DJOT'
- Item one
  - Nested A
  - Nested B
- Item two
DJOT;

        $result = $converter->convert($djot);

        // Should create nested list (2 <ul> tags)
        $this->assertEquals(2, substr_count($result, '<ul>'));
        $this->assertStringContainsString('Nested A', $result);
        $this->assertStringNotContainsString('- Nested', $result);
    }

    /**
     * Soft break mode can be overridden to NOT render as <br>.
     */
    public function testSoftBreaksWithSpaceMode(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        $djot = <<<'DJOT'
Line one
Line two
Line three
DJOT;

        $result = $converter->convert($djot);

        // Should NOT have <br> tags
        $this->assertStringNotContainsString('<br', $result);
    }

    /**
     * Standard significantNewlines mode DOES render soft breaks as <br>.
     */
    public function testSignificantNewlinesDefaultHasBrTags(): void
    {
        $converter = DjotConverter::withSignificantNewlines();

        $djot = <<<'DJOT'
Line one
Line two
Line three
DJOT;

        $result = $converter->convert($djot);

        // Should have <br> tags (backwards compatible behavior)
        $this->assertStringContainsString('<br', $result);
    }

    /**
     * Constructor with explicit softBreakMode.
     */
    public function testConstructorWithSoftBreakMode(): void
    {
        $converter = new DjotConverter(
            significantNewlines: true,
            softBreakMode: SoftBreakMode::Newline,
        );

        $djot = "Line one\nLine two";
        $result = $converter->convert($djot);

        // Should NOT have <br> tags
        $this->assertStringNotContainsString('<br', $result);
    }

    /**
     * significantNewlines allows blockquotes without blank lines.
     */
    public function testBlockquotesWithoutBlankLines(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        $djot = <<<'DJOT'
Some text
> A quote
More text
DJOT;

        $result = $converter->convert($djot);

        // Should have blockquote (significantNewlines allows block interruption)
        $this->assertStringContainsString('<blockquote>', $result);
    }

    /**
     * Nested ordered lists work with significantNewlines.
     */
    public function testNestedOrderedLists(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        $djot = <<<'DJOT'
1. First step
   - Detail A
   - Detail B
2. Second step
DJOT;

        $result = $converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('Detail A', $result);
    }

    /**
     * Deeply nested lists work with significantNewlines.
     */
    public function testDeeplyNestedLists(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        $djot = <<<'DJOT'
- Level 1
  - Level 2
    - Level 3
      - Level 4
- Back to 1
DJOT;

        $result = $converter->convert($djot);

        // Should have 4 nested <ul> tags
        $this->assertEquals(4, substr_count($result, '<ul>'));
    }

    /**
     * Default converter (strict mode) treats nested list markers as text.
     */
    public function testStrictModeForComparison(): void
    {
        $converter = new DjotConverter();

        $djot = <<<'DJOT'
- Item one
  - Nested A
- Item two
DJOT;

        $result = $converter->convert($djot);

        // Should only have 1 <ul> (nested marker treated as text)
        $this->assertEquals(1, substr_count($result, '<ul>'));
        // The "- Nested A" should appear as literal text
        $this->assertStringContainsString('- Nested A', $result);
    }

    /**
     * significantNewlines preserves tight/loose list distinction.
     */
    public function testTightLooseDistinctionPreserved(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        // Tight list (no blank lines between items)
        $tightDjot = <<<'DJOT'
- Item one
- Item two
- Item three
DJOT;

        $tightResult = $converter->convert($tightDjot);
        $this->assertStringNotContainsString('<p>Item', $tightResult);

        // Loose list (blank lines between items)
        $looseDjot = <<<'DJOT'
- Item one

- Item two

- Item three
DJOT;

        $looseResult = $converter->convert($looseDjot);
        $this->assertStringContainsString('<p>Item', $looseResult);
    }

    /**
     * Blank lines within nested lists should not split them into separate lists.
     *
     * This tests that both nesting paths (immediate and standard) handle
     * blank lines consistently.
     */
    public function testBlankLinesInNestedListsDoNotSplit(): void
    {
        $converter = DjotConverter::withSignificantNewlines(
            softBreakMode: SoftBreakMode::Space,
        );

        // Case 1: Immediate nesting (no blank line after parent)
        $djot1 = <<<'DJOT'
- Item one
  - Nested A
  - Nested B

  - Nested C
DJOT;

        $result1 = $converter->convert($djot1);

        // Should have exactly 2 <ul> tags (outer + one nested)
        $this->assertEquals(2, substr_count($result1, '<ul>'));
        // All nested items should be in the same list
        $this->assertStringContainsString('Nested A', $result1);
        $this->assertStringContainsString('Nested B', $result1);
        $this->assertStringContainsString('Nested C', $result1);

        // Case 2: Standard nesting (blank line after parent)
        $djot2 = <<<'DJOT'
- Item one

  - Nested A
  - Nested B

  - Nested C
DJOT;

        $result2 = $converter->convert($djot2);

        // Should also have exactly 2 <ul> tags
        $this->assertEquals(2, substr_count($result2, '<ul>'));

        // Both cases should produce the same nested list structure
        $this->assertEquals(
            substr_count($result1, '<ul>'),
            substr_count($result2, '<ul>'),
            'Both nesting paths should produce same number of lists',
        );
    }
}
