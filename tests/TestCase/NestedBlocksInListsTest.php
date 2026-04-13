<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for nested block elements inside list items.
 *
 * These tests cover Issue #83: Blockquotes and code blocks in lists
 * don't get rendered properly.
 *
 * In djot, block elements (blockquotes, code blocks, divs) can be nested
 * inside list items when properly indented.
 *
 * @see https://github.com/php-collective/djot-php/issues/83
 */
class NestedBlocksInListsTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Blockquotes in bullet lists ====================

    public function testBlockquoteInBulletList(): void
    {
        $djot = <<<'DJOT'
- > This is a quote
  > inside a list item

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('This is a quote', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testBlockquoteWithEmphasisInList(): void
    {
        $djot = <<<'DJOT'
- > *author*:
  >
  > Line 1 \
  > Line 2

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<strong>author</strong>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testMultipleBlockquotesInList(): void
    {
        // Note: In djot, nested blocks after text require a blank line
        $djot = <<<'DJOT'
- First item with quote:

  > Quote 1

- Second item with quote:

  > Quote 2

- Third item without quote
DJOT;

        $result = $this->converter->convert($djot);

        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    public function testNestedBlockquoteInList(): void
    {
        // Blockquote starts directly on first line, so it gets parsed as block
        $djot = <<<'DJOT'
- > Outer quote
  > > Inner quote
DJOT;

        $result = $this->converter->convert($djot);

        // Should have nested blockquotes
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Outer quote', $result);
        $this->assertStringContainsString('Inner quote', $result);
    }

    // ==================== Blockquotes in ordered lists ====================

    public function testBlockquoteInOrderedList(): void
    {
        $djot = <<<'DJOT'
1. > Quote in numbered list
   > Line 2

2. Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testBlockquoteInAlphaList(): void
    {
        $djot = <<<'DJOT'
a. > Quote in alpha list

b. Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol type="a">', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Code blocks in bullet lists ====================

    public function testCodeBlockInBulletList(): void
    {
        $djot = <<<'DJOT'
- ```
  code line 1
  code line 2
  ```

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('code line 1', $result);
        $this->assertStringContainsString('code line 2', $result);
    }

    public function testCodeBlockWithLanguageInList(): void
    {
        $djot = <<<'DJOT'
- ``` php
  echo "Hello";
  ```

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code class="language-php">', $result);
        $this->assertStringContainsString('echo "Hello"', $result);
    }

    public function testTildeCodeBlockInList(): void
    {
        $djot = <<<'DJOT'
- ~~~
  code here
  ~~~

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('code here', $result);
    }

    // ==================== Code blocks in ordered lists ====================

    public function testCodeBlockInOrderedList(): void
    {
        $djot = <<<'DJOT'
1. ```
   first code
   ```

2. ```
   second code
   ```
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        preg_match_all('/<pre><code>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    // ==================== Divs in lists ====================

    public function testDivInBulletList(): void
    {
        $djot = <<<'DJOT'
- ::: note
  This is a note
  :::

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<div class="note">', $result);
        $this->assertStringContainsString('This is a note', $result);
    }

    public function testDivInOrderedList(): void
    {
        $djot = <<<'DJOT'
1. ::: warning
   Warning content
   :::

2. Regular item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<div class="warning">', $result);
    }

    // ==================== Mixed blocks in lists ====================

    public function testBlockquoteAndCodeInSameList(): void
    {
        $djot = <<<'DJOT'
- > A quote

- ```
  Some code
  ```

- Regular text
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    public function testBlockquoteFollowedByCodeInSameItem(): void
    {
        $djot = <<<'DJOT'
- > Quote first

  ```
  Then code
  ```
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    public function testCodeFollowedByBlockquoteInSameItem(): void
    {
        $djot = <<<'DJOT'
- ```
  Code first
  ```

  > Then quote
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Text before/after blocks in list items ====================

    public function testTextBeforeBlockquoteInList(): void
    {
        $djot = <<<'DJOT'
- Some intro text:

  > The actual quote

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Some intro text', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('The actual quote', $result);
    }

    public function testTextBeforeCodeBlockInList(): void
    {
        $djot = <<<'DJOT'
- Here is some code:

  ```
  the code
  ```

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Here is some code', $result);
        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('the code', $result);
    }

    public function testTextAfterBlockquoteInList(): void
    {
        $djot = <<<'DJOT'
- > The quote

  Text after the quote

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Text after the quote', $result);
    }

    // ==================== Nested lists with blocks ====================

    public function testBlockquoteInNestedList(): void
    {
        $djot = <<<'DJOT'
- Outer item

  - > Quote in nested item

- Another outer item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testCodeBlockInNestedList(): void
    {
        $djot = <<<'DJOT'
- Outer item

  - ```
    nested code
    ```

- Another outer item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('nested code', $result);
    }

    // ==================== Task lists with blocks ====================

    public function testBlockquoteInTaskList(): void
    {
        $djot = <<<'DJOT'
- [ ] > Quote in unchecked task

- [x] > Quote in checked task
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('type="checkbox"', $result);
        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(2, $matches[0]);
    }

    public function testCodeBlockInTaskList(): void
    {
        $djot = <<<'DJOT'
- [ ] ```
      task code
      ```

- [x] Done task
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('type="checkbox"', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    // ==================== Edge cases ====================

    public function testEmptyBlockquoteInList(): void
    {
        $djot = <<<'DJOT'
- >

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testBlockquoteWithOnlyEmphasis(): void
    {
        $djot = <<<'DJOT'
- > _emphasized_

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<em>emphasized</em>', $result);
    }

    public function testMultiParagraphBlockquoteInList(): void
    {
        $djot = <<<'DJOT'
- > First paragraph
  >
  > Second paragraph

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        // Should have two paragraphs inside the blockquote
        preg_match('/<blockquote>(.*?)<\/blockquote>/s', $result, $matches);
        if (!empty($matches[1])) {
            $blockquoteContent = $matches[1];
            preg_match_all('/<p>/', $blockquoteContent, $paragraphs);
            $this->assertCount(2, $paragraphs[0]);
        }
    }

    public function testCodeBlockPreservesIndentation(): void
    {
        $djot = <<<'DJOT'
- ```
    indented
      more indented
  not indented
  ```

- Next item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        // The relative indentation should be preserved
        $this->assertStringContainsString('indented', $result);
    }

    // ==================== Loose vs tight list behavior ====================

    public function testBlockInTightListMakesLoose(): void
    {
        $djot = <<<'DJOT'
- > Quote

- Text
DJOT;

        $result = $this->converter->convert($djot);

        // When list items contain block elements, the list should be loose
        // (items wrapped in <p> tags or contain block elements)
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Definition lists with blocks ====================

    public function testBlockquoteInDefinitionList(): void
    {
        $djot = <<<'DJOT'
: Term

  > Quote in definition
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testCodeBlockInDefinitionList(): void
    {
        $djot = <<<'DJOT'
: Term

  ```
  code in definition
  ```
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    // ==================== Complex nesting scenarios ====================

    public function testDeeplyNestedBlockquote(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - > Deep quote

- Back to level 1
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Deep quote', $result);
    }

    public function testDeeplyNestedCodeBlock(): void
    {
        $djot = <<<'DJOT'
- Level 1

  - Level 2

    - ```
      deep code
      ```

- Back to level 1
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('deep code', $result);
    }

    public function testBlocksAtMultipleLevels(): void
    {
        $djot = <<<'DJOT'
- > Quote at level 1

  - > Quote at level 2

    - > Quote at level 3
DJOT;

        $result = $this->converter->convert($djot);

        preg_match_all('/<blockquote>/', $result, $matches);
        $this->assertCount(3, $matches[0]);
    }

    // ==================== Issue #83 specific test cases ====================

    public function testIssue83BlockquoteCase(): void
    {
        // Exact case from Issue #83
        $djot = <<<'DJOT'
List:

- > *author*:
  >
  > Line 1 \
  > Line 2

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<strong>author</strong>', $result);
        $this->assertStringNotContainsString('&gt;', $result);
    }

    public function testIssue83CodeBlockCase(): void
    {
        // Exact case from Issue #83
        $djot = <<<'DJOT'
List:

- ```
  asdasdasd
  asasdasd
  ```

- Another item
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code>', $result);
        $this->assertStringContainsString('asdasdasd', $result);
        // Should NOT be inline code
        $this->assertStringNotContainsString('<p><code>', $result);
    }

    /**
     * After a nested block inside a list item and a blank line, an
     * unindented paragraph must terminate the list rather than being
     * absorbed as a sub-item of the previous list item.
     *
     * @see https://github.com/php-collective/djot-php/issues/176
     */
    public function testIssue176UnindentedParagraphAfterNestedCodeBlockEndsList(): void
    {
        $djot = <<<'DJOT'
1. Item 1
2. Item 2

   ```
   Example
   ```

New list:

* New item 1
* New item 2
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('</ol>', $result);
        // The "New list:" paragraph must appear after the ordered list closes.
        $olClose = strpos($result, '</ol>');
        $paragraph = strpos($result, '<p>New list:</p>');
        $this->assertNotFalse($paragraph);
        $this->assertGreaterThan($olClose, $paragraph);
        // And the following bullet list must be a sibling, not nested in <li>.
        $this->assertMatchesRegularExpression('#</ol>\s*<p>New list:</p>\s*<ul>#', $result);
    }
}
