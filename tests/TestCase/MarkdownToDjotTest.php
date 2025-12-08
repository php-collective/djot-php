<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\MarkdownToDjot;
use PHPUnit\Framework\TestCase;

class MarkdownToDjotTest extends TestCase
{
    protected MarkdownToDjot $converter;

    protected function setUp(): void
    {
        $this->converter = new MarkdownToDjot();
    }

    public function testBoldDoubleAsterisk(): void
    {
        $markdown = 'This is **bold** text';
        $expected = 'This is *bold* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBoldDoubleUnderscore(): void
    {
        $markdown = 'This is __bold__ text';
        $expected = 'This is *bold* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testItalicSingleAsterisk(): void
    {
        $markdown = 'This is *italic* text';
        $expected = 'This is _italic_ text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testStrikethrough(): void
    {
        $markdown = 'This is ~~deleted~~ text';
        $expected = 'This is {-deleted-} text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testHighlight(): void
    {
        $markdown = 'This is ==highlighted== text';
        $expected = 'This is {=highlighted=} text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testSuperscript(): void
    {
        // ^text^ is already valid Djot, no conversion needed
        $markdown = 'E=mc^2^';
        $expected = 'E=mc^2^';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testSubscript(): void
    {
        // ~text~ is already valid Djot, no conversion needed
        $markdown = 'H~2~O';
        $expected = 'H~2~O';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMixedFormatting(): void
    {
        $markdown = 'This has **bold** and *italic* and ~~strike~~';
        $expected = 'This has *bold* and _italic_ and {-strike-}';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeSpanPreserved(): void
    {
        $markdown = 'Use `**not bold**` in code';
        $expected = 'Use `**not bold**` in code';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeBlockPreserved(): void
    {
        $markdown = "Normal **bold**\n\n```\n**not bold**\n```\n\nNormal **bold**";
        $expected = "Normal *bold*\n\n```\n**not bold**\n```\n\nNormal *bold*";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeBlockWithLanguage(): void
    {
        $markdown = "Some text\n\n```php\n\$x = **value**;\n```";
        $expected = "Some text\n\n```php\n\$x = **value**;\n```";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTildeFencedCodeBlock(): void
    {
        $markdown = "Some text\n\n~~~\n**not bold**\n~~~";
        $expected = "Some text\n\n~~~\n**not bold**\n~~~";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testLinksUnchanged(): void
    {
        $markdown = '[link text](https://example.com)';
        $expected = '[link text](https://example.com)';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testImagesUnchanged(): void
    {
        $markdown = '![alt text](image.png)';
        $expected = '![alt text](image.png)';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testSimpleListsUnchanged(): void
    {
        $markdown = "- item 1\n- item 2\n- item 3";
        $expected = "- item 1\n- item 2\n- item 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNumberedListsUnchanged(): void
    {
        $markdown = "1. first\n2. second\n3. third";
        $expected = "1. first\n2. second\n3. third";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBlockquotesUnchanged(): void
    {
        $markdown = "> This is a quote\n> with multiple lines";
        $expected = "> This is a quote\n> with multiple lines";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testThematicBreakUnchanged(): void
    {
        $markdown = "Before\n\n---\n\nAfter";
        $expected = "Before\n\n---\n\nAfter";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBoldAndItalicCombined(): void
    {
        $markdown = 'This is ***bold and italic*** text';
        // **bold** becomes *bold*, then remaining *italic* becomes _italic_
        $expected = 'This is *_bold and italic_* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNestedFormatting(): void
    {
        $markdown = '**bold with *italic* inside**';
        $expected = '*bold with _italic_ inside*';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testDjotBracedSyntaxUnchanged(): void
    {
        // Braced Djot syntax should remain unchanged
        $djot = 'This is {-delete-} and {=highlight=} and {^super^} and {~sub~}';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testDjotSuperscriptUnchanged(): void
    {
        // Already in Djot format should not be double-wrapped
        $djot = 'E=mc{^2^}';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testDjotSubscriptUnchanged(): void
    {
        // Already in Djot format should not be double-wrapped
        $djot = 'H{~2~}O';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testWhitespaceOnly(): void
    {
        $markdown = "   \n\n   ";
        $this->assertSame($markdown, $this->converter->convert($markdown));
    }

    public function testMultipleCodeSpans(): void
    {
        $markdown = 'Use `**bold**` and `*italic*` in code';
        $expected = 'Use `**bold**` and `*italic*` in code';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testInlineCodeNextToFormatting(): void
    {
        $markdown = '**bold** then `code` then *italic*';
        $expected = '*bold* then `code` then _italic_';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    // HTML tag to Djot conversion tests (for round-trip support)

    public function testHtmlMarkToHighlight(): void
    {
        $html = 'This is <mark>highlighted</mark> text';
        $expected = 'This is {=highlighted=} text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlInsToInsert(): void
    {
        $html = 'This is <ins>inserted</ins> text';
        $expected = 'This is {+inserted+} text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlDelToDelete(): void
    {
        $html = 'This is <del>deleted</del> text';
        $expected = 'This is {-deleted-} text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlSToDelete(): void
    {
        $html = 'This is <s>strikethrough</s> text';
        $expected = 'This is {-strikethrough-} text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlSupToSuperscript(): void
    {
        $html = 'E=mc<sup>2</sup>';
        $expected = 'E=mc^2^';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlSubToSubscript(): void
    {
        $html = 'H<sub>2</sub>O';
        $expected = 'H~2~O';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlEmToEmphasis(): void
    {
        $html = 'This is <em>emphasis</em> text';
        $expected = 'This is _emphasis_ text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlStrongToStrong(): void
    {
        $html = 'This is <strong>strong</strong> text';
        $expected = 'This is *strong* text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlBToBold(): void
    {
        $html = 'This is <b>bold</b> text';
        $expected = 'This is *bold* text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlIToItalic(): void
    {
        $html = 'This is <i>italic</i> text';
        $expected = 'This is _italic_ text';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlCodeToCode(): void
    {
        $html = 'Use the <code>convert</code> method';
        $expected = 'Use the `convert` method';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testHtmlTagsCaseInsensitive(): void
    {
        $html = '<MARK>highlight</MARK> and <SUP>super</SUP>';
        $expected = '{=highlight=} and ^super^';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    public function testMathDollarToBacktick(): void
    {
        $markdown = 'Inline $E=mc^2$ math';
        $expected = 'Inline $`E=mc^2` math';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMathDollarNotCurrency(): void
    {
        // Currency like $5 should not be converted
        $markdown = 'It costs $5 or $100';
        $expected = 'It costs $5 or $100';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMathDollarComplexExpression(): void
    {
        $markdown = 'The formula $\\sum_{i=0}^{n} i$ works';
        $expected = 'The formula $`\\sum_{i=0}^{n} i` works';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMixedHtmlAndMarkdown(): void
    {
        $mixed = '**bold** and <mark>highlight</mark> and *italic*';
        $expected = '*bold* and {=highlight=} and _italic_';

        $this->assertSame($expected, $this->converter->convert($mixed));
    }

    public function testRoundTripHighlight(): void
    {
        // Simulates djot -> markdown -> djot conversion
        $html = '<mark>text</mark>';
        $djot = $this->converter->convert($html);

        $this->assertSame('{=text=}', $djot);
    }

    public function testRoundTripSuperSubscript(): void
    {
        $html = 'H<sub>2</sub>O and E=mc<sup>2</sup>';
        $djot = $this->converter->convert($html);

        $this->assertSame('H~2~O and E=mc^2^', $djot);
    }

    // =========================================================================
    // Blank Line Handling Tests - Djot requires blank lines around block elements
    // =========================================================================

    public function testHeadingFollowedByText(): void
    {
        // Djot requires blank line after heading when followed by text
        $markdown = "# Heading 1\nSome text here.";
        $expected = "# Heading 1\n\nSome text here.";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testHeadingFollowedByHeading(): void
    {
        // Consecutive headings don't need blank line between them
        $markdown = "# Heading 1\n## Heading 2\n### Heading 3";
        $expected = "# Heading 1\n## Heading 2\n### Heading 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTextFollowedByHeading(): void
    {
        // Djot requires blank line before heading when preceded by text
        $markdown = "Some text.\n# Heading";
        $expected = "Some text.\n\n# Heading";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMultipleHeadingsWithText(): void
    {
        $markdown = "# Heading 1\nText 1\n## Heading 2\nText 2";
        $expected = "# Heading 1\n\nText 1\n\n## Heading 2\n\nText 2";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeFencePrecededByText(): void
    {
        // Djot requires blank line before code fence
        $markdown = "Some text\n```\ncode\n```";
        $expected = "Some text\n\n```\ncode\n```";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeFenceFollowedByText(): void
    {
        // Djot requires blank line after code fence
        $markdown = "```\ncode\n```\nSome text";
        $expected = "```\ncode\n```\n\nSome text";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeFencePreservedContent(): void
    {
        // Content inside code fence should not be modified
        $markdown = "```php\n# Not a heading\n- Not a list\n**Not bold**\n```";
        $expected = "```php\n# Not a heading\n- Not a list\n**Not bold**\n```";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTextFollowedByList(): void
    {
        // Djot requires blank line before list when preceded by text
        $markdown = "Some text\n- Item 1\n- Item 2";
        $expected = "Some text\n\n- Item 1\n- Item 2";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTextFollowedByNumberedList(): void
    {
        $markdown = "Some text\n1. First\n2. Second";
        $expected = "Some text\n\n1. First\n2. Second";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNestedUnorderedList(): void
    {
        // Djot requires blank line before nested list content
        $markdown = "- Item 1\n- Item 2\n  - Nested 1\n  - Nested 2\n- Item 3";
        $expected = "- Item 1\n- Item 2\n\n  - Nested 1\n  - Nested 2\n- Item 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNestedOrderedList(): void
    {
        // Djot requires blank line before nested list content
        $markdown = "1. First\n2. Second\n   1. Sub first\n   2. Sub second\n3. Third";
        $expected = "1. First\n2. Second\n\n   1. Sub first\n   2. Sub second\n3. Third";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testDeeplyNestedList(): void
    {
        $markdown = "- Level 1\n  - Level 2\n    - Level 3\n      - Level 4";
        // Each nested level gets a blank line before it
        $expected = "- Level 1\n\n  - Level 2\n\n    - Level 3\n\n      - Level 4";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMixedNestedList(): void
    {
        $markdown = "- Unordered\n  1. Nested ordered 1\n  2. Nested ordered 2\n- Back to unordered";
        $expected = "- Unordered\n\n  1. Nested ordered 1\n  2. Nested ordered 2\n- Back to unordered";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTextFollowedByBlockquote(): void
    {
        $markdown = "Some text\n> Quote line";
        $expected = "Some text\n\n> Quote line";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBlockquoteAfterHeading(): void
    {
        // Heading already adds blank line after
        $markdown = "# Heading\n> Quote";
        $expected = "# Heading\n\n> Quote";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testExcessiveBlankLinesNormalized(): void
    {
        // Multiple blank lines should be normalized to max 2
        $markdown = "Line 1\n\n\n\n\nLine 2";
        $expected = "Line 1\n\nLine 2";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testAlreadyProperBlankLines(): void
    {
        // If blank lines already exist, don't double them
        $markdown = "# Heading\n\nSome text\n\n- List item";
        $expected = "# Heading\n\nSome text\n\n- List item";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    // =========================================================================
    // Integration Tests - Full document conversion with rendering verification
    // =========================================================================

    public function testComplexDocumentWithBlankLines(): void
    {
        $markdown = <<<'MD'
# Welcome
This is a **bold** statement with *emphasis*.
## Features
- Item with ~~strikethrough~~
- Item with ==highlight==
  - Nested item 1
  - Nested item 2
- Item with `code`
```php
$bold = "**not converted**";
```
> A quote with **bold** text
[Link](https://example.com) and ![Image](img.png)
MD;

        $expected = <<<'DJOT'
# Welcome

This is a *bold* statement with _emphasis_.

## Features

- Item with {-strikethrough-}
- Item with {=highlight=}

  - Nested item 1
  - Nested item 2
- Item with `code`

```php
$bold = "**not converted**";
```

> A quote with *bold* text
[Link](https://example.com) and ![Image](img.png)
DJOT;

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTaskListConversion(): void
    {
        $markdown = "Some tasks:\n- [ ] Task 1\n- [x] Task 2\n- [ ] Task 3";
        // Task list syntax is preserved, but needs blank line before list
        $expected = "Some tasks:\n\n- [ ] Task 1\n- [x] Task 2\n- [ ] Task 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTableConversion(): void
    {
        // Tables should be unchanged except for blank line handling
        $markdown = "A table:\n| Header 1 | Header 2 |\n|----------|----------|\n| Cell 1   | Cell 2   |";
        // The table content is text, so it gets blank line treatment based on context
        $result = $this->converter->convert($markdown);

        // Just verify the table content is preserved
        $this->assertStringContainsString('| Header 1 | Header 2 |', $result);
        $this->assertStringContainsString('| Cell 1   | Cell 2   |', $result);
    }

    public function testMultipleCodeBlocksInDocument(): void
    {
        $markdown = <<<'MD'
First paragraph.
```js
const x = 1;
```
Second paragraph.
```python
x = 1
```
Third paragraph.
MD;

        $expected = <<<'DJOT'
First paragraph.

```js
const x = 1;
```

Second paragraph.

```python
x = 1
```

Third paragraph.
DJOT;

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testHeadingHierarchyWithContent(): void
    {
        $markdown = <<<'MD'
# Main Title
Introduction text.
## Section 1
Content for section 1.
### Subsection 1.1
Details here.
## Section 2
Content for section 2.
MD;

        $expected = <<<'DJOT'
# Main Title

Introduction text.

## Section 1

Content for section 1.

### Subsection 1.1

Details here.

## Section 2

Content for section 2.
DJOT;

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testListWithInlineFormatting(): void
    {
        $markdown = "Shopping list:\n- **Bold item**\n- *Italic item*\n- ~~Deleted item~~";
        $expected = "Shopping list:\n\n- *Bold item*\n- _Italic item_\n- {-Deleted item-}";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBlockquoteWithNestedFormatting(): void
    {
        $markdown = "> Quote with **bold** and *italic* text\n> Second line";
        // Blockquote preceded by start of document doesn't need extra blank line
        $expected = "> Quote with *bold* and _italic_ text\n> Second line";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }
}
