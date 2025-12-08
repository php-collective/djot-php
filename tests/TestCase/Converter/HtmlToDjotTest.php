<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use PHPUnit\Framework\TestCase;

class HtmlToDjotTest extends TestCase
{
    protected HtmlToDjot $converter;

    protected function setUp(): void
    {
        $this->converter = new HtmlToDjot();
    }

    // ==================== Basic Formatting ====================

    public function testParagraph(): void
    {
        $this->assertSame("Hello world\n", $this->converter->convert('<p>Hello world</p>'));
    }

    public function testStrong(): void
    {
        $this->assertSame("*bold*\n", $this->converter->convert('<strong>bold</strong>'));
        $this->assertSame("*bold*\n", $this->converter->convert('<b>bold</b>'));
    }

    public function testEmphasis(): void
    {
        $this->assertSame("_italic_\n", $this->converter->convert('<em>italic</em>'));
        $this->assertSame("_italic_\n", $this->converter->convert('<i>italic</i>'));
    }

    public function testUnderline(): void
    {
        $this->assertSame("{+underline+}\n", $this->converter->convert('<u>underline</u>'));
        $this->assertSame("{+inserted+}\n", $this->converter->convert('<ins>inserted</ins>'));
    }

    public function testStrikethrough(): void
    {
        $this->assertSame("{-deleted-}\n", $this->converter->convert('<s>deleted</s>'));
        $this->assertSame("{-deleted-}\n", $this->converter->convert('<del>deleted</del>'));
        $this->assertSame("{-deleted-}\n", $this->converter->convert('<strike>deleted</strike>'));
    }

    public function testHighlight(): void
    {
        $this->assertSame("{=highlighted=}\n", $this->converter->convert('<mark>highlighted</mark>'));
    }

    public function testSuperscript(): void
    {
        $this->assertSame("E=mc^2^\n", $this->converter->convert('E=mc<sup>2</sup>'));
    }

    public function testSubscript(): void
    {
        $this->assertSame("H~2~O\n", $this->converter->convert('H<sub>2</sub>O'));
    }

    public function testNestedFormatting(): void
    {
        $result = $this->converter->convert('<strong><em>bold italic</em></strong>');
        $this->assertSame("*_bold italic_*\n", $result);
    }

    public function testEmptyInlineTags(): void
    {
        // Empty tags should produce no output
        $this->assertSame("\n", $this->converter->convert('<strong></strong>'));
        $this->assertSame("\n", $this->converter->convert('<em></em>'));
        $this->assertSame("\n", $this->converter->convert('<sup></sup>'));
        $this->assertSame("\n", $this->converter->convert('<sub></sub>'));
        $this->assertSame("\n", $this->converter->convert('<del></del>'));
        $this->assertSame("\n", $this->converter->convert('<mark></mark>'));
        $this->assertSame("\n", $this->converter->convert('<ins></ins>'));
    }

    public function testWhitespaceInInlineTags(): void
    {
        // Whitespace should be trimmed
        $this->assertSame("E=mc^2^\n", $this->converter->convert('E=mc<sup> 2 </sup>'));
        $this->assertSame("H~2~O\n", $this->converter->convert('H<sub> 2 </sub>O'));
        $this->assertSame("*bold*\n", $this->converter->convert('<strong> bold </strong>'));
        $this->assertSame("{-deleted-}\n", $this->converter->convert('<del> deleted </del>'));
    }

    // ==================== Headings ====================

    public function testHeadings(): void
    {
        $this->assertSame("# Heading 1\n", $this->converter->convert('<h1>Heading 1</h1>'));
        $this->assertSame("## Heading 2\n", $this->converter->convert('<h2>Heading 2</h2>'));
        $this->assertSame("### Heading 3\n", $this->converter->convert('<h3>Heading 3</h3>'));
        $this->assertSame("#### Heading 4\n", $this->converter->convert('<h4>Heading 4</h4>'));
        $this->assertSame("##### Heading 5\n", $this->converter->convert('<h5>Heading 5</h5>'));
        $this->assertSame("###### Heading 6\n", $this->converter->convert('<h6>Heading 6</h6>'));
    }

    // ==================== Links ====================

    public function testLink(): void
    {
        $result = $this->converter->convert('<a href="https://example.com">Example</a>');
        $this->assertSame("[Example](https://example.com)\n", $result);
    }

    public function testLinkWithTitle(): void
    {
        $result = $this->converter->convert('<a href="https://example.com" title="Example Site">Example</a>');
        $this->assertSame("[Example](https://example.com \"Example Site\")\n", $result);
    }

    // ==================== Images ====================

    public function testImage(): void
    {
        $result = $this->converter->convert('<img src="image.jpg" alt="Alt text">');
        $this->assertSame("![Alt text](image.jpg)\n", $result);
    }

    public function testImageWithTitle(): void
    {
        $result = $this->converter->convert('<img src="image.jpg" alt="Alt" title="Title">');
        $this->assertSame("![Alt](image.jpg \"Title\")\n", $result);
    }

    // ==================== Code ====================

    public function testInlineCode(): void
    {
        $result = $this->converter->convert('Use <code>print()</code> function');
        $this->assertSame("Use `print()` function\n", $result);
    }

    public function testCodeBlock(): void
    {
        $result = $this->converter->convert('<pre><code>echo "hello";</code></pre>');
        $this->assertStringContainsString("```\n", $result);
        $this->assertStringContainsString('echo "hello";', $result);
    }

    public function testCodeBlockWithLanguage(): void
    {
        $result = $this->converter->convert('<pre><code class="language-php">echo "hello";</code></pre>');
        $this->assertStringContainsString("```php\n", $result);
    }

    // ==================== Block Elements ====================

    public function testBlockquote(): void
    {
        $result = $this->converter->convert('<blockquote>Quoted text</blockquote>');
        $this->assertStringContainsString('> Quoted text', $result);
    }

    public function testHorizontalRule(): void
    {
        $result = $this->converter->convert('<p>Above</p><hr><p>Below</p>');
        $this->assertStringContainsString('---', $result);
    }

    public function testLineBreak(): void
    {
        $result = $this->converter->convert('<p>Line one<br>Line two</p>');
        $this->assertStringContainsString("Line one\\\nLine two", $result);
    }

    // ==================== Lists ====================

    public function testUnorderedList(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
    }

    public function testOrderedList(): void
    {
        $html = '<ol><li>First</li><li>Second</li></ol>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);
    }

    public function testNestedList(): void
    {
        $html = '<ul><li>Item 1<ul><li>Nested</li></ul></li></ul>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('  - Nested', $result);
    }

    public function testOrderedListWithStart(): void
    {
        $html = '<ol start="5"><li>Fifth</li><li>Sixth</li></ol>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('5. Fifth', $result);
        $this->assertStringContainsString('6. Sixth', $result);
    }

    // ==================== Tables ====================

    public function testTable(): void
    {
        $html = <<<'HTML'
<table>
<thead><tr><th>Name</th><th>Age</th></tr></thead>
<tbody><tr><td>Alice</td><td>30</td></tr></tbody>
</table>
HTML;

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('| Name | Age |', $result);
        $this->assertStringContainsString('| --- | --- |', $result);
        $this->assertStringContainsString('| Alice | 30 |', $result);
    }

    // ==================== Definition Lists ====================

    public function testDefinitionList(): void
    {
        $html = '<dl><dt>Term</dt><dd>Definition</dd></dl>';
        $result = $this->converter->convert($html);

        // Djot format: `: term` for term, indented content for definition
        $this->assertStringContainsString(': Term', $result);
        $this->assertStringContainsString('  Definition', $result);
    }

    public function testDefinitionListMultipleTerms(): void
    {
        $html = '<dl><dt>color</dt><dt>colour</dt><dd>The visual property.</dd></dl>';
        $result = $this->converter->convert($html);

        // Multiple terms share one definition
        $this->assertStringContainsString(': color', $result);
        $this->assertStringContainsString(': colour', $result);
        $this->assertStringContainsString('  The visual property.', $result);
    }

    // ==================== Spans with Attributes ====================

    public function testSpanWithClass(): void
    {
        $result = $this->converter->convert('<span class="highlight">text</span>');
        $this->assertSame("[text]{.highlight}\n", $result);
    }

    public function testSpanWithId(): void
    {
        $result = $this->converter->convert('<span id="important">text</span>');
        $this->assertSame("[text]{#important}\n", $result);
    }

    public function testSpanWithClassAndId(): void
    {
        $result = $this->converter->convert('<span class="note" id="n1">text</span>');
        $this->assertSame("[text]{.note #n1}\n", $result);
    }

    // ==================== Figures ====================

    public function testFigure(): void
    {
        $html = '<figure><img src="photo.jpg" alt="Photo"><figcaption>A photo</figcaption></figure>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('![Photo](photo.jpg)', $result);
        $this->assertStringContainsString('^ A photo', $result);
    }

    // ==================== Complex Examples ====================

    public function testComplexDocument(): void
    {
        $html = <<<'HTML'
<article>
<h1>Welcome</h1>
<p>This is <strong>important</strong> and <em>emphasized</em>.</p>
<ul>
<li>First item</li>
<li>Second item</li>
</ul>
<blockquote>A quote</blockquote>
</article>
HTML;

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('# Welcome', $result);
        $this->assertStringContainsString('*important*', $result);
        $this->assertStringContainsString('_emphasized_', $result);
        $this->assertStringContainsString('- First item', $result);
        $this->assertStringContainsString('> A quote', $result);
    }

    public function testScriptAndStyleAreStripped(): void
    {
        $html = '<p>Text</p><script>alert("xss")</script><style>.x{}</style><p>More</p>';
        $result = $this->converter->convert($html);

        $this->assertStringNotContainsString('script', $result);
        $this->assertStringNotContainsString('style', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Text', $result);
        $this->assertStringContainsString('More', $result);
    }

    public function testWhitespaceNormalization(): void
    {
        $html = "<p>Multiple   spaces\n\nand\nnewlines</p>";
        $result = $this->converter->convert($html);

        // Should normalize to single spaces
        $this->assertSame("Multiple spaces and newlines\n", $result);
    }

    public function testExcessiveBlankLinesNormalized(): void
    {
        // Multiple block elements should not create more than 2 consecutive newlines
        $html = '<h1>Title</h1><p>Text</p><hr><h2>Section</h2>';
        $result = $this->converter->convert($html);

        // Should never have more than 2 consecutive newlines
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }

    // ==================== Blank Line Handling for Valid Djot ====================

    public function testNestedListWithBlankLine(): void
    {
        // Djot requires blank line before nested list content
        $html = '<ul><li>Item 1</li><li>Item 2<ul><li>Nested 1</li><li>Nested 2</li></ul></li><li>Item 3</li></ul>';
        $result = $this->converter->convert($html);

        // Verify nested list is properly indented
        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
        $this->assertStringContainsString('  - Nested 1', $result);
        $this->assertStringContainsString('  - Nested 2', $result);
        $this->assertStringContainsString('- Item 3', $result);

        // Verify blank line before nested list (required by Djot)
        $this->assertMatchesRegularExpression('/- Item 2\n\n\s+- Nested 1/', $result);
    }

    public function testDeeplyNestedList(): void
    {
        $html = '<ul><li>Level 1<ul><li>Level 2<ul><li>Level 3</li></ul></li></ul></li></ul>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('- Level 1', $result);
        $this->assertStringContainsString('  - Level 2', $result);
        $this->assertStringContainsString('    - Level 3', $result);
    }

    public function testMixedNestedLists(): void
    {
        $html = '<ul><li>Unordered<ol><li>Ordered 1</li><li>Ordered 2</li></ol></li></ul>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('- Unordered', $result);
        $this->assertStringContainsString('  1. Ordered 1', $result);
        $this->assertStringContainsString('  2. Ordered 2', $result);
    }

    public function testNestedOrderedList(): void
    {
        $html = '<ol><li>First</li><li>Second<ol><li>Sub A</li><li>Sub B</li></ol></li><li>Third</li></ol>';
        $result = $this->converter->convert($html);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);
        $this->assertStringContainsString('  1. Sub A', $result);
        $this->assertStringContainsString('  2. Sub B', $result);
        $this->assertStringContainsString('3. Third', $result);
    }

    public function testNoLeadingWhitespaceOnParagraphs(): void
    {
        $html = '  <p>  Text with surrounding whitespace  </p>  ';
        $result = $this->converter->convert($html);

        // Should not have leading whitespace
        $this->assertSame("Text with surrounding whitespace\n", $result);
    }

    public function testNoLeadingWhitespaceOnHeadings(): void
    {
        $html = '<h1>  Heading  </h1><p>  Text  </p>';
        $result = $this->converter->convert($html);

        $this->assertStringStartsWith('# Heading', $result);
        $this->assertStringContainsString('Text', $result);
        // No leading space on Text line
        $this->assertStringNotContainsString("\n Text", $result);
    }

    public function testCodeBlockPreservesIndentation(): void
    {
        $html = '<pre><code>  indented code
    more indented</code></pre>';
        $result = $this->converter->convert($html);

        // Indentation inside code should be preserved
        $this->assertStringContainsString('  indented code', $result);
        $this->assertStringContainsString('    more indented', $result);
    }

    public function testCompleteDocumentWithValidDjot(): void
    {
        $html = <<<'HTML'
<h1>Title</h1>
<p>Introduction paragraph.</p>
<h2>Section</h2>
<p>Some content.</p>
<ul>
  <li>Item 1</li>
  <li>Item 2
    <ul>
      <li>Nested item</li>
    </ul>
  </li>
</ul>
<pre><code class="language-php">echo "hello";</code></pre>
<blockquote><p>A quote</p></blockquote>
<p>Conclusion with <strong>bold</strong> and <em>italic</em>.</p>
HTML;

        $result = $this->converter->convert($html);

        // All elements should be present
        $this->assertStringContainsString('# Title', $result);
        $this->assertStringContainsString('Introduction paragraph.', $result);
        $this->assertStringContainsString('## Section', $result);
        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
        $this->assertStringContainsString('  - Nested item', $result);
        $this->assertStringContainsString('```php', $result);
        $this->assertStringContainsString('> A quote', $result);
        $this->assertStringContainsString('*bold*', $result);
        $this->assertStringContainsString('_italic_', $result);

        // Content lines should not have leading whitespace (except list indentation)
        $this->assertStringNotContainsString("\n Introduction", $result);
    }
}
