<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for nested list edge cases, particularly around block elements
 * following nested lists and content at parent indent levels.
 *
 * These tests cover bugs fixed in the BlockParser where:
 * 1. Headings after deeply nested lists were absorbed into the list
 * 2. Content at parent indent level after nested content was absorbed into nested item
 */
class NestedListEdgeCasesTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Headings after nested lists ====================

    public function testHeadingAfterDeeplyNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - Level 3

      - Level 4

        - Level 5

### Mixed List Types
DJOT;

        $result = $this->converter->convert($djot);

        // Heading should be outside the list
        $this->assertStringContainsString('</ul>', $result);
        $this->assertStringContainsString('<h3>Mixed List Types</h3>', $result);

        // Heading should come after the list closes
        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h3>');
        $this->assertGreaterThan($lastUlPos, $headingPos, 'Heading should appear after list closes');
    }

    public function testHeadingAfterTwoLevelNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

### Heading
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('</ul>', $result);
        $this->assertStringContainsString('<h3>Heading</h3>', $result);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h3>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    public function testCodeFenceAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

```php
code
```
DJOT;

        $result = $this->converter->convert($djot);

        // Code block should be outside the list
        $lastUlPos = strrpos($result, '</ul>');
        $codePos = strpos($result, '<pre><code');
        $this->assertGreaterThan($lastUlPos, $codePos);
    }

    public function testThematicBreakAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

---
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $hrPos = strpos($result, '<hr>');
        $this->assertGreaterThan($lastUlPos, $hrPos);
    }

    public function testDivAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

::: note
Content
:::
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $divPos = strpos($result, '<div');
        $this->assertGreaterThan($lastUlPos, $divPos);
    }

    // ==================== Content at parent indent level ====================

    public function testContentAtParentLevelAfterNestedContent(): void
    {
        $djot = <<<'DJOT'
- A

  - B

    P

  C
DJOT;

        $result = $this->converter->convert($djot);

        // "C" should be part of list item "A", not "B"
        // Check structure: <li>A ... <ul><li>B ... P</li></ul> ... C</li>
        $this->assertStringContainsString('<p>C</p>', $result);

        // The <p>C</p> should be inside the outer <li> but after the inner </ul>
        preg_match('/<li[^>]*>.*?<p>A<\/p>.*?<ul>.*?<\/ul>(.*?)<\/li>/s', $result, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringContainsString('<p>C</p>', $matches[1]);
    }

    public function testContentAtParentLevelInDeeperNesting(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    Paragraph in level 2.

  Back at level 1.

- Another top item
DJOT;

        $result = $this->converter->convert($djot);

        // "Back at level 1." should be part of first top-level item
        $this->assertStringContainsString('<p>Back at level 1.</p>', $result);

        // "Back at level 1." should NOT be inside the nested list (Level 2)
        // It should appear after </ul> (the nested list) but before the outer </li>
        $nestedListEnd = strpos($result, '</ul>');
        $backAtLevel1Pos = strpos($result, 'Back at level 1.');
        $this->assertGreaterThan($nestedListEnd, $backAtLevel1Pos);
    }

    public function testSimpleCaseStillWorks(): void
    {
        // Without nested content at higher indent, parent-level content should work
        $djot = <<<'DJOT'
- A

  - B

  C
DJOT;

        $result = $this->converter->convert($djot);

        // "C" should be part of "A"
        $this->assertStringContainsString('<p>C</p>', $result);
    }

    // ==================== Tight vs loose list behavior ====================

    public function testTightNestedListWithHeading(): void
    {
        $djot = <<<'DJOT'
- A
  - B
    - C

### Heading
DJOT;

        $result = $this->converter->convert($djot);

        // Should be tight list (no <p> wrappers)
        $this->assertStringNotContainsString('<li><p>A</p>', $result);
        $this->assertStringContainsString('<h3>Heading</h3>', $result);
    }

    public function testLooseNestedListWithHeading(): void
    {
        $djot = <<<'DJOT'
- A

  - B

### Heading
DJOT;

        $result = $this->converter->convert($djot);

        // Heading should be outside the list
        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h3>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    // ==================== Multiple headings and blocks ====================

    public function testMultipleHeadingsAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Item

  - Nested

## First Heading

Text between.

## Second Heading
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<h2>First Heading</h2>', $result);
        $this->assertStringContainsString('<h2>Second Heading</h2>', $result);

        // Both headings should be after the list
        $lastUlPos = strrpos($result, '</ul>');
        $h1Pos = strpos($result, '<h2>First Heading');
        $h2Pos = strpos($result, '<h2>Second Heading');
        $this->assertGreaterThan($lastUlPos, $h1Pos);
        $this->assertGreaterThan($lastUlPos, $h2Pos);
    }

    public function testMixedBlocksAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Item

  - Nested

## Heading

```
code
```

> quote
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $codePos = strpos($result, '<pre>');
        $quotePos = strpos($result, '<blockquote>');

        $this->assertGreaterThan($lastUlPos, $headingPos);
        $this->assertGreaterThan($lastUlPos, $codePos);
        $this->assertGreaterThan($lastUlPos, $quotePos);
    }

    // ==================== Edge cases ====================

    public function testHeadingAtLevel1Only(): void
    {
        $djot = <<<'DJOT'
- Item

# Heading
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('</ul>', $result);
        $this->assertStringContainsString('<h1>Heading</h1>', $result);
    }

    public function testEmptyNestedListWithHeading(): void
    {
        $djot = <<<'DJOT'
- Item

  -

## Heading
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    public function testNestedListWithBlankLinesAndHeading(): void
    {
        $djot = <<<'DJOT'
- A

  - B


## Heading
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    public function testOrderedNestedListWithHeading(): void
    {
        $djot = <<<'DJOT'
1. First

   1. Nested first

   2. Nested second

## Heading
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('</ol>', $result);
        $this->assertStringContainsString('<h2>Heading</h2>', $result);

        $lastOlPos = strrpos($result, '</ol>');
        $headingPos = strpos($result, '<h2>');
        $this->assertGreaterThan($lastOlPos, $headingPos);
    }

    public function testMixedListTypesWithHeading(): void
    {
        $djot = <<<'DJOT'
- Bullet

  1. Ordered nested

## Heading
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    // ==================== Block quotes after nested lists ====================

    public function testBlockQuoteAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

> Quote text
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $quotePos = strpos($result, '<blockquote>');
        $this->assertGreaterThan($lastUlPos, $quotePos);
    }

    public function testBlockQuoteAfterDeeplyNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - Level 3

> This is a quote
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $quotePos = strpos($result, '<blockquote>');
        $this->assertGreaterThan($lastUlPos, $quotePos);
        $this->assertStringContainsString('<p>This is a quote</p>', $result);
    }

    // ==================== Tables after nested lists ====================

    public function testTableAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

| A | B |
|---|---|
| 1 | 2 |
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $tablePos = strpos($result, '<table>');
        $this->assertGreaterThan($lastUlPos, $tablePos);
    }

    public function testTableAfterDeeplyNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - Level 3

| Col1 | Col2 |
|------|------|
| X    | Y    |
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $tablePos = strpos($result, '<table>');
        $this->assertGreaterThan($lastUlPos, $tablePos);
    }

    // ==================== List type transitions ====================

    public function testOrderedListAfterUnorderedNestedList(): void
    {
        $djot = <<<'DJOT'
- Bullet 1

  - Nested bullet

1. Ordered item
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $olPos = strpos($result, '<ol>');
        $this->assertNotFalse($olPos);
        $this->assertGreaterThan($lastUlPos, $olPos);
    }

    public function testUnorderedListAfterOrderedNestedList(): void
    {
        $djot = <<<'DJOT'
1. First

   1. Nested ordered

- Bullet item
DJOT;

        $result = $this->converter->convert($djot);

        $lastOlPos = strrpos($result, '</ol>');
        $ulPos = strpos($result, '<ul>');
        $this->assertNotFalse($ulPos);
        $this->assertGreaterThan($lastOlPos, $ulPos);
    }

    public function testTaskListAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Item 1

  - Nested item

- [ ] Task item
DJOT;

        $result = $this->converter->convert($djot);

        // Task list should be separate from the nested list
        $this->assertStringContainsString('type="checkbox"', $result);
    }

    // ==================== Definition lists after nested lists ====================

    public function testDefinitionListAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

: Term

  Definition here
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $dlPos = strpos($result, '<dl>');
        $this->assertNotFalse($dlPos);
        $this->assertGreaterThan($lastUlPos, $dlPos);
    }

    public function testDefinitionListAfterDeeplyNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - Level 3

: First term

  First definition

: Second term

  Second definition
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $dlPos = strpos($result, '<dl>');
        $this->assertNotFalse($dlPos);
        $this->assertGreaterThan($lastUlPos, $dlPos);
        $this->assertStringContainsString('<dt>First term</dt>', $result);
        $this->assertStringContainsString('<dt>Second term</dt>', $result);
    }

    // ==================== Mixed block elements after nested lists ====================

    public function testMultipleBlockTypesAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

## Heading

> Quote

| A |
|---|
| B |

: Term

  Def
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');

        // All block elements should appear after the list
        $this->assertGreaterThan($lastUlPos, strpos($result, '<h2>'));
        $this->assertGreaterThan($lastUlPos, strpos($result, '<blockquote>'));
        $this->assertGreaterThan($lastUlPos, strpos($result, '<table>'));
        $this->assertGreaterThan($lastUlPos, strpos($result, '<dl>'));
    }

    // ==================== Fenced comments after nested lists ====================

    public function testCommentFenceAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

%%%
This is a comment
%%%
DJOT;

        $result = $this->converter->convert($djot);

        // Comment should not appear in output
        $this->assertStringNotContainsString('This is a comment', $result);
        // List should be complete
        $this->assertStringContainsString('</ul>', $result);
    }

    // ==================== Different list markers ====================

    public function testPlusMarkerListAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Dash item

  - Nested dash

+ Plus item
DJOT;

        $result = $this->converter->convert($djot);

        // Should have two separate lists
        preg_match_all('/<ul>/', $result, $matches);
        $this->assertGreaterThanOrEqual(2, count($matches[0]));
    }

    public function testAsteriskMarkerListAfterNestedList(): void
    {
        $djot = <<<'DJOT'
- Dash item

  - Nested dash

* Asterisk item
DJOT;

        $result = $this->converter->convert($djot);

        // Should have two separate lists
        preg_match_all('/<ul>/', $result, $matches);
        $this->assertGreaterThanOrEqual(2, count($matches[0]));
    }

    public function testLetterOrderedListAfterNestedList(): void
    {
        $djot = <<<'DJOT'
1. Number item

   1. Nested number

a. Letter item
DJOT;

        $result = $this->converter->convert($djot);

        // Should have ordered lists
        $this->assertStringContainsString('<ol>', $result);
    }

    // ==================== Edge cases with blank lines ====================

    public function testMultipleBlankLinesBeforeBlockElement(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2



## Heading
DJOT;

        $result = $this->converter->convert($djot);

        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }

    public function testNoBlankLineBeforeBlockElement(): void
    {
        // In standard mode, without blank lines, tight list content continues
        // Block elements only interrupt after a blank line
        $djot = <<<'DJOT'
- Level 1
  - Level 2
## Heading
DJOT;

        $result = $this->converter->convert($djot);

        // Without blank lines, content is absorbed into the list item (expected)
        // This tests that tight list behavior is preserved
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('Level 1', $result);
    }

    public function testSingleBlankLineAllowsBlockElement(): void
    {
        // With a single blank line, block elements correctly break out
        $djot = <<<'DJOT'
- Level 1
  - Level 2

## Heading
DJOT;

        $result = $this->converter->convert($djot);

        // Heading should be outside the list
        $lastUlPos = strrpos($result, '</ul>');
        $headingPos = strpos($result, '<h2>');
        $this->assertNotFalse($headingPos);
        $this->assertGreaterThan($lastUlPos, $headingPos);
    }
}
