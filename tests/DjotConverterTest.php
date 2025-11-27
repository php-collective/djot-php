<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Symbol;
use PHPUnit\Framework\TestCase;

class DjotConverterTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testParagraph(): void
    {
        $djot = 'Hello world';
        $expected = "<p>Hello world</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testMultipleParagraphs(): void
    {
        $djot = "First paragraph\n\nSecond paragraph";
        $expected = "<p>First paragraph</p>\n<p>Second paragraph</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testHeadings(): void
    {
        $djot = "# Heading 1\n\n## Heading 2\n\n### Heading 3";
        $expected = "<h1>Heading 1</h1>\n<h2>Heading 2</h2>\n<h3>Heading 3</h3>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testEmphasis(): void
    {
        $djot = 'This is _emphasized_ text';
        $expected = "<p>This is <em>emphasized</em> text</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testStrong(): void
    {
        $djot = 'This is *strong* text';
        $expected = "<p>This is <strong>strong</strong> text</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testInlineCode(): void
    {
        $djot = 'Use the `print()` function';
        $expected = "<p>Use the <code>print()</code> function</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlock(): void
    {
        $djot = "```php\necho 'hello';\n```";
        $expected = "<pre><code class=\"language-php\">echo &apos;hello&apos;;</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlockWithoutLanguage(): void
    {
        $djot = "```\nplain code\n```";
        $expected = "<pre><code>plain code</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLink(): void
    {
        $djot = 'Visit [Example](https://example.com)';
        $expected = "<p>Visit <a href=\"https://example.com\">Example</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testImage(): void
    {
        $djot = '![Alt text](image.png)';
        $expected = "<p><img src=\"image.png\" alt=\"Alt text\"></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testAutolink(): void
    {
        $djot = 'Visit <https://example.com>';
        $expected = "<p>Visit <a href=\"https://example.com\">https://example.com</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testEmailAutolink(): void
    {
        $djot = 'Contact <user@example.com>';
        $expected = "<p>Contact <a href=\"mailto:user@example.com\">user@example.com</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBlockQuote(): void
    {
        $djot = "> This is a quote\n> with multiple lines";
        $expected = "<blockquote>\n<p>This is a quote with multiple lines</p>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBulletList(): void
    {
        $djot = "- Item 1\n- Item 2\n- Item 3";
        $expected = "<ul>\n<li>Item 1</li>\n<li>Item 2</li>\n<li>Item 3</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testOrderedList(): void
    {
        $djot = "1. First\n2. Second\n3. Third";
        $expected = "<ol>\n<li>First</li>\n<li>Second</li>\n<li>Third</li>\n</ol>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testTaskList(): void
    {
        $djot = "- [ ] Unchecked\n- [x] Checked";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('task-list', $result);
        $this->assertStringContainsString('type="checkbox"', $result);
        $this->assertStringContainsString('checked=""', $result);
        $this->assertStringContainsString('Unchecked', $result);
        $this->assertStringContainsString('Checked', $result);
    }

    public function testThematicBreak(): void
    {
        $djot = "Before\n\n***\n\nAfter";
        $expected = "<p>Before</p>\n<hr>\n<p>After</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testDiv(): void
    {
        $djot = "::: warning\nThis is a warning\n:::";
        $expected = "<div class=\"warning\">\n<p>This is a warning</p>\n</div>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testHighlight(): void
    {
        $djot = 'This is {=highlighted=} text';
        $expected = "<p>This is <mark>highlighted</mark> text</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testInsert(): void
    {
        $djot = 'This is {+inserted+} text';
        $expected = "<p>This is <ins>inserted</ins> text</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testDelete(): void
    {
        $djot = 'This is {-deleted-} text';
        $expected = "<p>This is <del>deleted</del> text</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSuperscript(): void
    {
        $djot = 'E=mc^2^';
        $expected = "<p>E=mc<sup>2</sup></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSubscript(): void
    {
        $djot = 'H~2~O';
        $expected = "<p>H<sub>2</sub>O</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSmartQuotes(): void
    {
        $djot = '"Hello," she said.';
        $expected = "<p>\u{201C}Hello,\u{201D} she said.</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSmartDashes(): void
    {
        $djot = 'en-dash -- and em-dash ---';
        $expected = "<p>en-dash \u{2013} and em-dash \u{2014}</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testEllipsis(): void
    {
        $djot = 'Wait for it...';
        $expected = "<p>Wait for it\u{2026}</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testHardBreak(): void
    {
        $djot = "Line one\\\nLine two";
        $expected = "<p>Line one<br>\nLine two</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSpanWithClass(): void
    {
        $djot = '[text]{.highlight}';
        $expected = "<p><span class=\"highlight\">text</span></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSpanWithId(): void
    {
        $djot = '[text]{#myid}';
        $expected = "<p><span id=\"myid\">text</span></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testTable(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<thead>', $result);
        $this->assertStringContainsString('<th>A</th>', $result);
        $this->assertStringContainsString('<th>B</th>', $result);
        $this->assertStringContainsString('<tbody>', $result);
        $this->assertStringContainsString('<td>1</td>', $result);
        $this->assertStringContainsString('<td>2</td>', $result);
    }

    public function testTableAlignment(): void
    {
        $djot = "| Left | Center | Right |\n|:-----|:------:|------:|\n| L    | C      | R     |";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('text-align: left', $result);
        $this->assertStringContainsString('text-align: center', $result);
        $this->assertStringContainsString('text-align: right', $result);
    }

    public function testReferenceLink(): void
    {
        $djot = "[Example][ex]\n\n[ex]: https://example.com";
        $expected = "<p><a href=\"https://example.com\">Example</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testNestedEmphasis(): void
    {
        $djot = '_This is *strong inside* emphasis_';
        $expected = "<p><em>This is <strong>strong inside</strong> emphasis</em></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testEscapedCharacters(): void
    {
        $djot = 'Not \_emphasis\_ and not \*strong\*';
        $expected = "<p>Not _emphasis_ and not *strong*</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testXhtmlMode(): void
    {
        $converter = new DjotConverter(true);

        $djot = "---\n\n![image](test.png)";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<hr />', $result);
        $this->assertStringContainsString('/>', $result);
    }

    public function testComplexDocument(): void
    {
        $djot = <<<DJOT
# Welcome

This is a *comprehensive* test of the _Djot_ parser.

## Features

- Inline formatting
- Code blocks
- Links and images

```php
echo "Hello, Djot!";
```

> A wise quote
> from someone smart

Visit [our site](https://example.com) for more.
DJOT;

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<h1>Welcome</h1>', $result);
        $this->assertStringContainsString('<strong>comprehensive</strong>', $result);
        $this->assertStringContainsString('<em>Djot</em>', $result);
        $this->assertStringContainsString('<h2>Features</h2>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('language-php', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<a href="https://example.com">', $result);
    }

    public function testFootnotes(): void
    {
        $djot = "Here is a footnote reference[^1].\n\n[^1]: This is the footnote content.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('fnref-1', $result);
        $this->assertStringContainsString('fn-1', $result);
        $this->assertStringContainsString('footnote content', $result);
    }

    public function testDefinitionList(): void
    {
        $djot = "Term\n: Definition of the term";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<dt>Term</dt>', $result);
        $this->assertStringContainsString('<dd>Definition of the term</dd>', $result);
    }

    public function testBlockAttributes(): void
    {
        $djot = "{.warning}\n# Important Notice";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="warning"', $result);
        $this->assertStringContainsString('<h1', $result);
        $this->assertStringContainsString('Important Notice', $result);
    }

    public function testBlockAttributesWithId(): void
    {
        $djot = "{#intro}\nThis is the introduction.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('id="intro"', $result);
    }

    public function testRawBlock(): void
    {
        $djot = "``` =html\n<div class=\"custom\">Raw HTML</div>\n```";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<div class="custom">Raw HTML</div>', $result);
    }

    public function testMathInline(): void
    {
        $djot = 'The equation is $`E = mc^2`$.';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="math inline"', $result);
        $this->assertStringContainsString('E = mc^2', $result);
    }

    public function testMathDisplay(): void
    {
        $djot = 'Display math: $$`\sum_{i=0}^n i`$$';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="math display"', $result);
    }

    public function testSymbol(): void
    {
        $djot = 'I :heart: Djot!';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString(':heart:', $result);
    }

    public function testMultipleBlockAttributes(): void
    {
        $djot = "{.info #notice data-type=alert}\nThis is an alert.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="info"', $result);
        $this->assertStringContainsString('id="notice"', $result);
        $this->assertStringContainsString('data-type="alert"', $result);
    }

    public function testDefinitionListMultiple(): void
    {
        $djot = "Apple\n: A fruit\n\nBanana\n: Another fruit";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>Apple</dt>', $result);
        $this->assertStringContainsString('<dd>A fruit</dd>', $result);
        $this->assertStringContainsString('<dt>Banana</dt>', $result);
        $this->assertStringContainsString('<dd>Another fruit</dd>', $result);
    }

    public function testComment(): void
    {
        $djot = "Before\n\n{% This is a comment %}\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('comment', $result);
    }

    public function testMultilineComment(): void
    {
        $djot = "Before\n\n{% This is a\nmultiline\ncomment %}\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('multiline', $result);
    }

    // Edge cases from official Djot test suite

    public function testEmphasisIntraword(): void
    {
        $djot = 'foo_bar_baz';
        $expected = "<p>foo<em>bar</em>baz</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testStrongIntraword(): void
    {
        $djot = 'foo*bar*baz';
        $expected = "<p>foo<strong>bar</strong>baz</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testEmphasisWithSpacesAtBoundary(): void
    {
        // Space after opener - should not be emphasis
        $djot = '_ foo bar_';

        $result = $this->converter->convert($djot);
        $this->assertStringNotContainsString('<em>', $result);
    }

    public function testStrongWithSpacesAtBoundary(): void
    {
        // Space after opener - should not be strong
        $djot = '* foo bar*';

        $result = $this->converter->convert($djot);
        $this->assertStringNotContainsString('<strong>', $result);
    }

    public function testEscapedUnderscore(): void
    {
        // Basic escape works
        $djot = 'Not \_emphasis\_';
        $expected = "<p>Not _emphasis_</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlockWithTildes(): void
    {
        $djot = "~~~\ncode\n~~~";
        $expected = "<pre><code>code</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlockWithTildesAndLanguage(): void
    {
        $djot = "~~~python\ndef hello():\n    pass\n~~~";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="language-python"', $result);
        $this->assertStringContainsString('def hello():', $result);
    }

    public function testCodeBlockWithLanguage(): void
    {
        $djot = "``` python\ndef hello():\n    pass\n```";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="language-python"', $result);
        $this->assertStringContainsString('def hello():', $result);
    }

    public function testLinkWithEmphasis(): void
    {
        $djot = '[_emphasized link_](url)';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<a href="url">', $result);
        $this->assertStringContainsString('<em>emphasized link</em>', $result);
    }

    public function testImageWithEmphasis(): void
    {
        $djot = '![_alt text_](image.png)';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('alt=', $result);
    }

    public function testNestedBlockquote(): void
    {
        $djot = "> Level 1\n>\n> > Level 2";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Level 1', $result);
    }

    public function testCodeTakesPrecedenceOverEmphasis(): void
    {
        $djot = '`_not emphasis_`';
        $expected = "<p><code>_not emphasis_</code></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testAutolinkTakesPrecedenceOverEmphasis(): void
    {
        $djot = '<https://example.com/_path_>';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<a href="https://example.com/_path_">', $result);
        $this->assertStringNotContainsString('<em>', $result);
    }

    public function testDivWithoutClass(): void
    {
        $djot = ":::\nContent\n:::";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<div>', $result);
        $this->assertStringContainsString('Content', $result);
    }

    public function testNestedDivs(): void
    {
        $djot = "::: outer\n::: inner\nNested\n:::\n:::";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="outer"', $result);
        $this->assertStringContainsString('class="inner"', $result);
    }

    public function testHeadingWithInlineFormatting(): void
    {
        $djot = '# Hello *world* and _everyone_';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<h1>', $result);
        $this->assertStringContainsString('<strong>world</strong>', $result);
        $this->assertStringContainsString('<em>everyone</em>', $result);
    }

    public function testListWithParagraphs(): void
    {
        $djot = "- Item 1\n\n  More text\n\n- Item 2";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('Item 1', $result);
        $this->assertStringContainsString('Item 2', $result);
    }

    public function testOrderedListStartNumber(): void
    {
        $djot = "5. First\n6. Second";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol', $result);
        $this->assertStringContainsString('First', $result);
    }

    public function testThematicBreakDashes(): void
    {
        $djot = "Before\n\n---\n\nAfter";
        $expected = "<p>Before</p>\n<hr>\n<p>After</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testSpanWithMultipleClasses(): void
    {
        $djot = '[text]{.class1 .class2}';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<span', $result);
        $this->assertStringContainsString('class1', $result);
        $this->assertStringContainsString('class2', $result);
    }

    public function testSpanWithCustomAttribute(): void
    {
        $djot = '[text]{data-value=123}';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('data-value="123"', $result);
    }

    public function testEmptyParagraph(): void
    {
        // Just whitespace should produce nothing
        $djot = "   \n\n   ";

        $result = $this->converter->convert($djot);

        $this->assertSame('', trim($result));
    }

    public function testConsecutiveHeadings(): void
    {
        $djot = "# One\n## Two\n### Three";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<h1>One</h1>', $result);
        $this->assertStringContainsString('<h2>Two</h2>', $result);
        $this->assertStringContainsString('<h3>Three</h3>', $result);
    }

    public function testTableWithInlineFormatting(): void
    {
        $djot = "| *Strong* | _Emphasis_ |\n|---|---|\n| `code` | text |";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>Strong</strong>', $result);
        $this->assertStringContainsString('<em>Emphasis</em>', $result);
        $this->assertStringContainsString('<code>code</code>', $result);
    }

    public function testMultipleFootnotes(): void
    {
        $djot = "First[^a] and second[^b].\n\n[^a]: Note A\n[^b]: Note B";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Note A', $result);
        $this->assertStringContainsString('Note B', $result);
    }

    public function testRawInline(): void
    {
        // Raw inline HTML syntax: `content`{=html}
        $djot = 'Text `<span class="x">raw</span>`{=html} more';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<span class="x">raw</span>', $result);
        $this->assertStringContainsString('Text', $result);
        $this->assertStringContainsString('more', $result);
    }

    public function testSymbolsMultiple(): void
    {
        $djot = ':heart: and :star:';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString(':heart:', $result);
        $this->assertStringContainsString(':star:', $result);
    }

    public function testMixedEmphasisAndStrong(): void
    {
        $djot = '*_strong and emphasis_*';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    public function testHtmlEntities(): void
    {
        $djot = 'Less < and greater > and amp &';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    public function testBlockAttributesOnCodeBlock(): void
    {
        $djot = "{.highlight}\n```\ncode\n```";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="highlight"', $result);
        $this->assertStringContainsString('<pre', $result);
    }

    public function testBlockAttributesOnBlockquote(): void
    {
        $djot = "{.important}\n> Quote text";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="important"', $result);
        $this->assertStringContainsString('<blockquote', $result);
    }

    public function testDefinitionListWithMultipleDefinitions(): void
    {
        $djot = "Term\n: First definition\n: Second definition";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>Term</dt>', $result);
        $this->assertStringContainsString('<dd>First definition</dd>', $result);
        $this->assertStringContainsString('<dd>Second definition</dd>', $result);
    }

    public function testLinkWithAttributes(): void
    {
        $djot = '[Click here](https://example.com){.btn .primary #main-link}';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('class="btn primary"', $result);
        $this->assertStringContainsString('id="main-link"', $result);
    }

    public function testLinkWithCustomAttribute(): void
    {
        $djot = '[Download](file.pdf){target=_blank rel=noopener}';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener"', $result);
    }

    public function testImageWithAttributes(): void
    {
        $djot = '![Logo](logo.png){.logo width=200 height=100}';

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('src="logo.png"', $result);
        $this->assertStringContainsString('class="logo"', $result);
        $this->assertStringContainsString('width="200"', $result);
        $this->assertStringContainsString('height="100"', $result);
    }

    public function testReferenceLinkWithAttributes(): void
    {
        $djot = "[Example][ex]{.external}\n\n[ex]: https://example.com";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('class="external"', $result);
    }

    public function testLineBlock(): void
    {
        $djot = "| Line one\n| Line two\n| Line three";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="line-block"', $result);
        $this->assertStringContainsString('Line one', $result);
        $this->assertStringContainsString('Line two', $result);
        $this->assertStringContainsString('<br>', $result);
    }

    public function testLineBlockWithFormatting(): void
    {
        $djot = "| This is *strong*\n| And _emphasis_";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>strong</strong>', $result);
        $this->assertStringContainsString('<em>emphasis</em>', $result);
    }

    // Event system tests

    public function testEventModifyNode(): void
    {
        $this->converter->on('render.link', function (RenderEvent $event): void {
            $link = $event->getNode();
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener');
        });

        $result = $this->converter->convert('[Click](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener"', $result);
    }

    public function testEventReplaceHtml(): void
    {
        $this->converter->on('render.symbol', function (RenderEvent $event): void {
            $symbol = $event->getNode();
            if ($symbol instanceof Symbol) {
                $html = match ($symbol->getName()) {
                    'heart' => '❤️',
                    'star' => '⭐',
                    default => ':' . $symbol->getName() . ':',
                };
                $event->setHtml($html);
            }
        });

        $result = $this->converter->convert('I :heart: Djot!');

        $this->assertStringContainsString('❤️', $result);
    }

    public function testEventPreventDefault(): void
    {
        $this->converter->on('render.heading', function (RenderEvent $event): void {
            $heading = $event->getNode();
            if ($heading instanceof Heading) {
                // Replace all headings with custom wrapper
                $level = $heading->getLevel();
                $event->setHtml('<div class="custom-h' . $level . '">Custom Heading</div>' . "\n");
            }
        });

        $result = $this->converter->convert('# Title');

        $this->assertStringContainsString('class="custom-h1"', $result);
        $this->assertStringNotContainsString('<h1>', $result);
    }

    public function testEventWildcard(): void
    {
        $nodeTypes = [];
        $this->converter->on('render.*', function (RenderEvent $event) use (&$nodeTypes): void {
            $nodeTypes[] = $event->getNode()->getType();
        });

        $this->converter->convert("# Hello\n\nWorld");

        $this->assertContains('heading', $nodeTypes);
        $this->assertContains('paragraph', $nodeTypes);
        $this->assertContains('text', $nodeTypes);
    }

    public function testEventOff(): void
    {
        $called = false;
        $this->converter->on('render.link', function () use (&$called): void {
            $called = true;
        });

        $this->converter->off('render.link');
        $this->converter->convert('[test](url)');

        $this->assertFalse($called);
    }

    public function testEventChaining(): void
    {
        $result = $this->converter
            ->on('render.link', function (RenderEvent $event): void {
                $event->getNode()->setAttribute('class', 'link');
            })
            ->on('render.strong', function (RenderEvent $event): void {
                $event->getNode()->setAttribute('class', 'bold');
            })
            ->convert('[link](url) and *strong*');

        $this->assertStringContainsString('class="link"', $result);
        $this->assertStringContainsString('class="bold"', $result);
    }
}
