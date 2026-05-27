<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Exception\ParseException;
use Djot\Extension\HeadingLevelShiftExtension;
use Djot\Extension\TabsExtension;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Symbol;
use Djot\Profile;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\SoftBreakMode;
use LengthException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Transliterator;

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
        // Headings are wrapped in <section> per djot spec
        // Nested headings create nested sections (h2 inside h1 section, etc.)
        $djot = "# Heading 1\n\n## Heading 2\n\n### Heading 3";
        $expected = "<section id=\"Heading-1\">\n<h1>Heading 1</h1>\n"
            . "<section id=\"Heading-2\">\n<h2>Heading 2</h2>\n"
            . "<section id=\"Heading-3\">\n<h3>Heading 3</h3>\n"
            . "</section>\n</section>\n</section>\n";

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
        $expected = "<pre><code class=\"language-php\">echo 'hello';\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlockLanguageEscapesQuotesInAttributeContext(): void
    {
        $djot = "``` php\" onclick=\"alert(1)\necho 1;\n```";
        $expected = "<pre><code class=\"language-php&quot; onclick=&quot;alert(1)\">echo 1;\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testCodeBlockWithoutLanguage(): void
    {
        $djot = "```\nplain code\n```";
        $expected = "<pre><code>plain code\n</code></pre>\n";

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
        $expected = "<p><img alt=\"Alt text\" src=\"image.png\"></p>\n";

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
        // Djot preserves newlines
        $expected = "<blockquote>\n<p>This is a quote\nwith multiple lines</p>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBulletList(): void
    {
        $djot = "- Item 1\n- Item 2\n- Item 3";
        $expected = "<ul>\n<li>\nItem 1\n</li>\n<li>\nItem 2\n</li>\n<li>\nItem 3\n</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testOrderedList(): void
    {
        $djot = "1. First\n2. Second\n3. Third";
        $expected = "<ol>\n<li>\nFirst\n</li>\n<li>\nSecond\n</li>\n<li>\nThird\n</li>\n</ol>\n";

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

    public function testTaskListMergesExistingClasses(): void
    {
        $djot = "{.outer}\n- [ ] Task";
        $expected = "<ul class=\"outer task-list\">\n<li>\n<input disabled=\"\" type=\"checkbox\"/>\nTask\n</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testTaskListUnderscoreNotation(): void
    {
        $djot = "- [_] Unchecked with underscore\n- [ ] Unchecked with space\n- [x] Checked";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('task-list', $result);
        // Both underscore and space should render as unchecked checkboxes
        $this->assertSame(2, substr_count($result, '<input disabled="" type="checkbox"/>'));
        $this->assertSame(1, substr_count($result, 'checked=""'));
        $this->assertStringContainsString('Unchecked with underscore', $result);
        $this->assertStringContainsString('Unchecked with space', $result);
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

        // djot doesn't use thead/tbody - just th and td cells
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>A</th>', $result);
        $this->assertStringContainsString('<th>B</th>', $result);
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

    public function testTableCaption(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |\n^ This is a caption";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<caption>This is a caption</caption>', $result);
        $this->assertStringContainsString('<table>', $result);
    }

    public function testTableCaptionWithBlankLine(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |\n\n^ Caption after blank line";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<caption>Caption after blank line</caption>', $result);
    }

    public function testTableCaptionMultiline(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |\n^ This is a long caption\nthat continues on the next line";

        $result = $this->converter->convert($djot);

        // Djot preserves newlines in captions
        $this->assertStringContainsString("<caption>This is a long caption\nthat continues on the next line</caption>", $result);
    }

    public function testReferenceLink(): void
    {
        $djot = "[Example][ex]\n\n[ex]: https://example.com";
        $expected = "<p><a href=\"https://example.com\">Example</a></p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testReferenceLinkWithTitle(): void
    {
        $djot = "[Example][ex]\n\n{title=\"My Title\"}\n[ex]: https://example.com";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('title="My Title"', $result);
    }

    public function testReferenceLinkWithClass(): void
    {
        $djot = "[Example][ex]\n\n{.external}\n[ex]: https://example.com";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="external"', $result);
    }

    public function testReferenceLinkAttributesOverride(): void
    {
        // Link-level attributes should override definition-level
        $djot = "[Example][ex]{.local}\n\n{.external}\n[ex]: https://example.com";

        $result = $this->converter->convert($djot);

        // Should have both classes (definition first, then link)
        $this->assertStringContainsString('external', $result);
        $this->assertStringContainsString('local', $result);
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

        // Headings are now wrapped in section per djot spec
        $this->assertStringContainsString('<section id="Welcome">', $result);
        $this->assertStringContainsString('<h1>Welcome</h1>', $result);
        $this->assertStringContainsString('<strong>comprehensive</strong>', $result);
        $this->assertStringContainsString('<em>Djot</em>', $result);
        $this->assertStringContainsString('<section id="Features">', $result);
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

        // Footnotes now use numbered IDs per djot spec
        $this->assertStringContainsString('fnref1', $result);
        $this->assertStringContainsString('fn1', $result);
        $this->assertStringContainsString('footnote content', $result);
        $this->assertStringContainsString('role="doc-endnotes"', $result);
    }

    public function testFootnotesUseXhtmlHrInXhtmlMode(): void
    {
        $converter = new DjotConverter(true);

        $result = $converter->convert("Here is a footnote[^1].\n\n[^1]: Footnote.");

        $this->assertStringContainsString('<section role="doc-endnotes">', $result);
        $this->assertStringContainsString('<hr />', $result);
    }

    public function testDefinitionList(): void
    {
        $djot = ": Term\n\n  Definition of the term";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl>', $result);
        $this->assertStringContainsString('<dt>Term</dt>', $result);
        $this->assertStringContainsString('Definition of the term', $result);
        $this->assertStringContainsString('<dd>', $result);
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
        $djot = ": Apple\n\n  A fruit\n\n: Banana\n\n  Another fruit";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>Apple</dt>', $result);
        $this->assertStringContainsString('A fruit', $result);
        $this->assertStringContainsString('<dt>Banana</dt>', $result);
        $this->assertStringContainsString('Another fruit', $result);
    }

    public function testDefinitionListMultipleTerms(): void
    {
        $djot = ": CLI\n: Command Line Interface\n\n  A text-based interface for interacting with computers.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>CLI</dt>', $result);
        $this->assertStringContainsString('<dt>Command Line Interface</dt>', $result);
        $this->assertStringContainsString('A text-based interface', $result);
        // Both terms should share a single definition
        $this->assertSame(1, substr_count($result, '<dd>'));
    }

    public function testDefinitionListMultipleTermsWithBlankLines(): void
    {
        $djot = ": color\n\n: colour\n\n  The visual property of objects.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>color</dt>', $result);
        $this->assertStringContainsString('<dt>colour</dt>', $result);
        $this->assertSame(1, substr_count($result, '<dd>'));
    }

    public function testDefinitionListMultipleTermsMultipleDefinitions(): void
    {
        // Use `: +` continuation marker to create multiple dd elements
        $djot = ": color\n: colour\n\n  The visual property of objects.\n\n: +\n\n  Used in art and design.";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>color</dt>', $result);
        $this->assertStringContainsString('<dt>colour</dt>', $result);
        $this->assertStringContainsString('The visual property', $result);
        $this->assertStringContainsString('Used in art and design', $result);
        // `: +` marker creates second dd element
        $this->assertSame(2, substr_count($result, '<dd>'));
    }

    public function testDefinitionListDlAttribute(): void
    {
        $djot = "{.vocabulary}\n: Term\n\n  Definition";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl class="vocabulary">', $result);
        $this->assertStringContainsString('<dt>Term</dt>', $result);
    }

    public function testDefinitionListDtAttribute(): void
    {
        $djot = ": Term\n{.highlighted}\n\n  Definition";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt class="highlighted">Term</dt>', $result);
    }

    public function testDefinitionListDdAttribute(): void
    {
        // DD attribute comes AFTER content (consistent with list items)
        $djot = ": Term\n\n  Definition content\n  {.note}";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dd class="note">', $result);
        $this->assertStringContainsString('Definition content', $result);
    }

    public function testDefinitionListAllAttributes(): void
    {
        // DD attributes come AFTER content (consistent with list items)
        // Use `: +` continuation marker to create multiple dd elements with separate attributes
        $djot = "{.vocabulary}\n: color\n{.american}\n: colour\n{.british}\n\n  The visual property.\n  {.primary}\n\n: +\n\n  Used in design.\n  {.secondary}";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dl class="vocabulary">', $result);
        $this->assertStringContainsString('<dt class="american">color</dt>', $result);
        $this->assertStringContainsString('<dt class="british">colour</dt>', $result);
        $this->assertStringContainsString('<dd class="primary">', $result);
        $this->assertStringContainsString('<dd class="secondary">', $result);
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

    public function testFencedComment(): void
    {
        $djot = "Before\n\n%%%\nThis is a fenced comment\n%%%\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('fenced comment', $result);
    }

    public function testFencedCommentWithBlankLines(): void
    {
        $djot = "Before\n\n%%%\nComment line 1\n\nBlank line above\n\nComment line 3\n%%%\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('Comment line', $result);
        $this->assertStringNotContainsString('Blank line', $result);
    }

    public function testFencedCommentWithMorePercents(): void
    {
        // Can use more than 3 percent signs, and can contain shorter runs inside
        $djot = "Before\n\n%%%%\n%% not closing\n%%% also not closing\n%%%%\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('not closing', $result);
    }

    public function testFencedCommentClosingNeedsMatchingLength(): void
    {
        // Closing fence must have at least as many % as opening
        $djot = "Before\n\n%%%%\ncomment\n%%%\nstill comment\n%%%%\n\nAfter";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('comment', $result);
        $this->assertStringNotContainsString('still', $result);
    }

    public function testFencedCommentBreaksParagraphContinuity(): void
    {
        // Fenced comments are block-level elements that break paragraph continuity
        // Text before and after becomes two separate paragraphs
        $djot = "Lorem ipsum\n\n%%%\ncomment\n%%%\n\ndolor sit amet";

        $result = $this->converter->convert($djot);

        // Should produce two separate paragraphs
        $this->assertStringContainsString('<p>Lorem ipsum</p>', $result);
        $this->assertStringContainsString('<p>dolor sit amet</p>', $result);
        $this->assertStringNotContainsString('comment', $result);

        // Should NOT be a single paragraph
        $this->assertStringNotContainsString('Lorem ipsum dolor', $result);
    }

    public function testFencedCommentInterruptsParagraph(): void
    {
        // Fenced comments can interrupt paragraphs without requiring blank lines
        // This makes comments truly "invisible" from a formatting perspective
        $djot = "Lorem ipsum\n%%%\ncomment\n%%%\ndolor sit amet";

        $result = $this->converter->convert($djot);

        // Should produce two separate paragraphs with comment stripped
        $this->assertStringContainsString('<p>Lorem ipsum</p>', $result);
        $this->assertStringContainsString('<p>dolor sit amet</p>', $result);
        $this->assertStringNotContainsString('comment', $result);
        $this->assertStringNotContainsString('%%%', $result);
    }

    public function testFencedCommentWithBlankLinesAlsoWorks(): void
    {
        // Also works with blank lines (traditional block element style)
        $djot = "Lorem ipsum\n\n%%%\ncomment\n%%%\n\ndolor sit amet";

        $result = $this->converter->convert($djot);

        // Comment should be recognized and stripped
        $this->assertStringContainsString('<p>Lorem ipsum</p>', $result);
        $this->assertStringContainsString('<p>dolor sit amet</p>', $result);
        $this->assertStringNotContainsString('comment', $result);
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

    /**
     * Test that attributes inside emphasis don't break delimiter matching
     *
     * Regression test: Special characters like * inside quoted attribute values
     * should not be treated as emphasis delimiters.
     */
    public function testAttributesInsideEmphasis(): void
    {
        // The * inside key="*" should not close the strong
        $djot = 'a *b{#id key="*"}*';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('</strong>', $result);
        $this->assertStringContainsString('id="id"', $result);
        $this->assertStringContainsString('key="*"', $result);
    }

    /**
     * Test that unclosed emphasis with attributes creates span instead
     *
     * When emphasis cannot close because the only potential closer is inside
     * an attribute, the attribute attaches to the preceding word.
     */
    public function testAttributesWithDelimiterInValueNoClose(): void
    {
        $djot = 'a *b{#id key="*"}o';
        $result = $this->converter->convert($djot);

        // No closing * so *b stays literal, attribute attaches to *b
        $this->assertStringContainsString('<span id="id" key="*">*b</span>', $result);
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
        $expected = "<pre><code>code\n</code></pre>\n";

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

        // Headings wrapped in section per djot spec
        $this->assertStringContainsString('<section id="Hello-world-and-everyone">', $result);
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

    public function testListItemAttributes(): void
    {
        $djot = "- item 1\n  {.highlight}\n- item 2";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li class="highlight">', $result);
        $this->assertStringContainsString('item 1', $result);
        $this->assertStringContainsString('item 2', $result);
    }

    public function testListItemAttributesWithId(): void
    {
        $djot = "- first item\n  {#first .important}\n- second item";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li id="first" class="important">', $result);
    }

    public function testListItemAttributesWithCustomAttribute(): void
    {
        $djot = "- item\n  {data-value=\"test\"}";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('data-value="test"', $result);
    }

    public function testOrderedListItemAttributes(): void
    {
        $djot = "1. first\n   {.step-one}\n2. second";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li class="step-one">', $result);
    }

    public function testTaskListItemAttributes(): void
    {
        $djot = "- [x] done\n  {.completed}\n- [ ] pending";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li class="completed">', $result);
        $this->assertStringContainsString('checked=""', $result);
    }

    public function testListItemAttributesFollowedByBlockquoteStaysInItem(): void
    {
        // G2: a {...} line followed by more content within the same item
        // must NOT terminate the list. The {...} reverts to a normal
        // block-attribute for the following blockquote inside the item.
        $djot = "- item\n  {.x}\n  > quote\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('<blockquote class="x">', $result);
        $this->assertStringNotContainsString('&gt; quote', $result);
    }

    public function testListItemAttributesFollowedByParagraphStaysInItem(): void
    {
        // G2: continuation text after a {...} line must remain in the item,
        // not escape to a sibling paragraph outside the list.
        $djot = "- item one\n  {.x}\n  more text\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('item one', $result);
        $this->assertStringContainsString('more text', $result);
        // "more text" must be INSIDE the <ul>, not a sibling.
        $ulClose = strpos($result, '</ul>');
        $moreTextPos = strpos($result, 'more text');
        $this->assertNotFalse($ulClose);
        $this->assertNotFalse($moreTextPos);
        $this->assertLessThan($ulClose, $moreTextPos);
    }

    public function testListItemAttributesFollowedByNestedListStaysInItem(): void
    {
        // G2: a nested list after a {...} line must stay nested, not
        // escape to literal text outside the parent list. The {.x}
        // reverts to a block attribute attached to the nested <ul>.
        $djot = "- item\n  {.x}\n  - nested\n";

        $result = $this->converter->convert($djot);

        // Expect two nested <ul> opens (the outer <ul> and the inner
        // one that picks up {.x}), NOT a "<p>- nested</p>" escape.
        $this->assertSame(2, substr_count($result, '<ul'));
        $this->assertStringNotContainsString('<p>- nested</p>', $result);
        $this->assertStringContainsString('<ul class="x">', $result);
    }

    public function testListItemAttributesLastLineUnchanged(): void
    {
        // G2 regression guard: when {.x} IS the last line of the item it
        // still attaches to the <li> exactly as before.
        $djot = "- item\n  {.x}\n- second\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li class="x">', $result);
    }

    public function testListItemAttributesLooseListUnchanged(): void
    {
        // G2 regression guard: blank-line separator (loose item) keeps
        // the previously-working behavior — {.x} attaches to the <li>,
        // and the following blockquote sits inside the item.
        $djot = "- item\n  {.x}\n\n  > quote\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li class="x">', $result);
        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testListItemAttributeStartIsStrippedFromLi(): void
    {
        // G3: `start` is an HTML attribute valid only on <ol>. It must
        // be stripped from <li> output, never feed <ol>, and never
        // appear in HTML.
        $djot = "1. item\n   {start=5}\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li>', $result);
        $this->assertStringNotContainsString('<li start=', $result);
        $this->assertStringNotContainsString('<ol start="5"', $result);
    }

    public function testListItemAttributeTypeIsStrippedFromLi(): void
    {
        // G3: `type` is an HTML attribute valid only on <ol>. Stripped
        // from <li>; never overrides marker-derived <ol type=...>.
        $djot = "(a) x\n   {type=i}\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol type="a">', $result);
        $this->assertStringNotContainsString('<li type=', $result);
        $this->assertStringNotContainsString('type="i"', $result);
    }

    public function testListItemAttributeReversedIsStrippedFromLi(): void
    {
        // G3: `reversed` is <ol>-only. Stripped from <li>.
        $djot = "1. item\n   {reversed}\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<li>', $result);
        $this->assertStringNotContainsString('<li reversed', $result);
        $this->assertStringNotContainsString('reversed=""', $result);
    }

    public function testListItemOtherAttributesUnchanged(): void
    {
        // G3 regression guard: only start/type/reversed are stripped.
        // class, id, data-* pass through unchanged on <li>.
        $djot = "1. item\n   {#anchor .step data-step=\"1\"}\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('id="anchor"', $result);
        $this->assertStringContainsString('class="step"', $result);
        $this->assertStringContainsString('data-step="1"', $result);
    }

    public function testOlStartFromBlockAttrUnchanged(): void
    {
        // G3 regression guard: a block-attribute line BEFORE a list
        // applies to the <ol> and must still emit start/type as before.
        $djot = "{start=5}\n1. item\n2. next\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol start="5"', $result);
    }

    public function testDefinitionListDdStartIsStripped(): void
    {
        // G3: same strip applies to <dd>.
        $djot = ": term\n\n  definition\n  {start=5}\n";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dd>', $result);
        $this->assertStringNotContainsString('<dd start=', $result);
    }

    public function testRomanNumeralList(): void
    {
        // x. is parsed as Roman numeral 10
        $djot = "x. first\nx. second\nx. third";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol start="10" type="i">', $result);
        $this->assertStringContainsString('first', $result);
        $this->assertStringContainsString('second', $result);
        $this->assertStringContainsString('third', $result);
    }

    public function testRomanNumeralListUppercase(): void
    {
        // X. is parsed as uppercase Roman numeral 10
        $djot = "X. first\nX. second\nX. third";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<ol start="10" type="I">', $result);
        $this->assertStringContainsString('first', $result);
        $this->assertStringContainsString('second', $result);
        $this->assertStringContainsString('third', $result);
    }

    public function testAlphabeticListWithAmbiguousMarkers(): void
    {
        // 'c' could be alpha (3rd) or roman (100), but should continue as alpha after a, b
        $djot = "a. first\nb. second\nc. third";

        $result = $this->converter->convert($djot);

        // Should be one list with 3 items starting at 'a', not two lists
        $this->assertStringContainsString('<ol type="a">', $result);
        $this->assertSame(1, substr_count($result, '<ol'));
        $this->assertSame(3, substr_count($result, '<li>'));
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

        // Headings are wrapped in section per djot spec
        $this->assertStringContainsString('<section id="One">', $result);
        $this->assertStringContainsString('<h1>One</h1>', $result);
        $this->assertStringContainsString('<section id="Two">', $result);
        $this->assertStringContainsString('<h2>Two</h2>', $result);
        $this->assertStringContainsString('<section id="Three">', $result);
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

    public function testMultipleReferencesToSameFootnote(): void
    {
        $djot = "First[^x] and second[^x] and third[^x].\n\n[^x]: Shared note";

        $result = $this->converter->convert($djot);

        // All references to same footnote share the same ID (numbered by first reference)
        // In djot spec, all refs get same id="fnref1" href="#fn1"
        $this->assertStringContainsString('id="fnref1"', $result);
        $this->assertStringContainsString('href="#fn1"', $result);
        $this->assertStringContainsString('role="doc-noteref"', $result);

        // Footnote at end with backlink
        $this->assertStringContainsString('<li id="fn1">', $result);
        $this->assertStringContainsString('href="#fnref1"', $result);
        $this->assertStringContainsString('role="doc-backlink"', $result);
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
        // Use `: +` continuation marker to create multiple dd elements
        $djot = ": Term\n\n  First definition\n\n: +\n\n  Second definition";

        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>Term</dt>', $result);
        $this->assertStringContainsString('First definition', $result);
        $this->assertStringContainsString('Second definition', $result);
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

    public function testLineBlockMergesExistingClasses(): void
    {
        $djot = "{.mine}\n| Line one\n| Line two";
        $expected = "<div class=\"mine line-block\">\n<p>Line one<br>\nLine two</p>\n</div>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
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

    public function testParseFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/djot_test_' . uniqid() . '.djot';
        file_put_contents($tempFile, "# Hello\n\nWorld");

        try {
            $document = $this->converter->parseFile($tempFile);
            $this->assertCount(2, $document->getChildren());

            $result = $this->converter->render($document);
            $this->assertStringContainsString('<section id="Hello">', $result);
            $this->assertStringContainsString('<h1>Hello</h1>', $result);
            $this->assertStringContainsString('<p>World</p>', $result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testConvertFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/djot_test_' . uniqid() . '.djot';
        file_put_contents($tempFile, "# Hello\n\nWorld");

        try {
            $result = $this->converter->convertFile($tempFile);

            $this->assertStringContainsString('<section id="Hello">', $result);
            $this->assertStringContainsString('<h1>Hello</h1>', $result);
            $this->assertStringContainsString('<p>World</p>', $result);
        } finally {
            unlink($tempFile);
        }
    }

    public function testConvertFileAppliesProfileFiltering(): void
    {
        $tempFile = sys_get_temp_dir() . '/djot_test_' . uniqid() . '.djot';
        file_put_contents($tempFile, "# Heading\n\n[link](https://example.com)");

        try {
            $converter = new DjotConverter(profile: Profile::comment());
            $result = $converter->convertFile($tempFile);

            $this->assertStringNotContainsString('<h1>', $result);
            $this->assertStringContainsString('rel="nofollow ugc"', $result);
            $this->assertTrue($converter->hasProfileViolations());
        } finally {
            unlink($tempFile);
        }
    }

    public function testConvertFileEnforcesProfileMaxLength(): void
    {
        $tempFile = sys_get_temp_dir() . '/djot_test_' . uniqid() . '.djot';
        file_put_contents($tempFile, str_repeat('a', 20));

        try {
            $converter = new DjotConverter(profile: Profile::minimal()->setMaxLength(5));

            $this->expectException(LengthException::class);
            $converter->convertFile($tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    public function testParseFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->converter->parseFile('/nonexistent/file.djot');
    }

    public function testConvertFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->converter->convertFile('/nonexistent/file.djot');
    }

    // Warning and strict mode tests

    public function testWarningsDisabledByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->convert("```php\ncode without closing fence");

        $this->assertEmpty($converter->getWarnings());
        $this->assertFalse($converter->hasWarnings());
    }

    public function testWarningsCollectionEnabled(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("```php\ncode without closing fence");

        $this->assertTrue($converter->hasWarnings());
        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Unclosed code fence', $warnings[0]->getMessage());
        $this->assertSame(1, $warnings[0]->getLine());
    }

    public function testStrictModeThrowsOnUnclosedCodeFence(): void
    {
        $converter = new DjotConverter(strict: true);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed code fence');

        $converter->convert("```php\ncode without closing fence");
    }

    public function testStrictModeThrowsOnUnclosedDiv(): void
    {
        $converter = new DjotConverter(strict: true);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed div');

        $converter->convert("::: warning\nSome content without closing");
    }

    public function testStrictModeThrowsOnUnclosedComment(): void
    {
        $converter = new DjotConverter(strict: true);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed comment');

        $converter->convert('{% This comment never closes');
    }

    public function testStrictModeThrowsOnUnclosedRawBlock(): void
    {
        $converter = new DjotConverter(strict: true);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unclosed raw block');

        $converter->convert("``` =html\n<div>no closing fence");
    }

    public function testWarningForUndefinedReference(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Link text][missing]');

        $this->assertTrue($converter->hasWarnings());
        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Undefined reference 'missing'", $warnings[0]->getMessage());
    }

    public function testWarningForUndefinedFootnote(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('Text with footnote[^missing]');

        $this->assertTrue($converter->hasWarnings());
        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Undefined footnote 'missing'", $warnings[0]->getMessage());
    }

    public function testNoWarningForDefinedReference(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[Link text][ref]\n\n[ref]: https://example.com");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testNoWarningForDefinedFootnote(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("Text[^note]\n\n[^note]: Footnote content");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testWarningLineNumber(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("Line 1\n\nLine 3\n\n```php\ncode");

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(5, $warnings[0]->getLine());
    }

    public function testParseExceptionLineNumber(): void
    {
        $converter = new DjotConverter(strict: true);

        try {
            $converter->convert("Line 1\n\nLine 3\n\n```php\ncode");
            $this->fail('Expected ParseException was not thrown');
        } catch (ParseException $e) {
            $this->assertSame(5, $e->getSourceLine());
            $this->assertSame(1, $e->getSourceColumn());
        }
    }

    public function testMultipleWarnings(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[Missing ref][ref1]\n\n[Another][ref2]\n\nFootnote[^missing]");

        $warnings = $converter->getWarnings();
        $this->assertCount(3, $warnings);
    }

    public function testClearWarnings(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $this->assertTrue($converter->hasWarnings());

        $converter->clearWarnings();
        $this->assertFalse($converter->hasWarnings());
    }

    public function testWarningsAndStrictModeTogether(): void
    {
        $converter = new DjotConverter(warnings: true, strict: true);

        // Should throw on error but still have warnings enabled
        $this->expectException(ParseException::class);
        $converter->convert("```\nunclosed");
    }

    public function testWarningToArray(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);

        $array = $warnings[0]->toArray();
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('column', $array);
    }

    public function testWarningToString(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $warnings = $converter->getWarnings();
        $string = (string)$warnings[0];

        $this->assertStringContainsString('Undefined reference', $string);
        $this->assertStringContainsString('line', $string);
        $this->assertStringContainsString('column', $string);
    }

    public function testNestedDivWarningLineNumber(): void
    {
        $converter = new DjotConverter(warnings: true);
        $djot = <<<'DJOT'
::: outer
Line 2

::: inner
Line 5
unclosed inner
:::

Line 9
DJOT;
        // The outer div is unclosed
        $djot = <<<'DJOT'
::: outer
Line 2

```php
unclosed code
DJOT;
        $converter->convert($djot);

        $warnings = $converter->getWarnings();
        // Should have warnings for unclosed code fence and unclosed div
        $this->assertGreaterThanOrEqual(1, count($warnings));
    }

    public function testClosedBlocksNoWarnings(): void
    {
        $converter = new DjotConverter(warnings: true);
        $djot = <<<'DJOT'
```php
function test() {}
```

::: note
This is a note.
:::

{% This is a comment %}
DJOT;
        $converter->convert($djot);

        $this->assertFalse($converter->hasWarnings());
    }

    // ==================== Edge Case Tests ====================

    // Edge cases: Empty and whitespace input

    public function testEmptyInput(): void
    {
        $result = $this->converter->convert('');
        $this->assertSame('', $result);
    }

    public function testWhitespaceOnlyInput(): void
    {
        $result = $this->converter->convert("   \n  \t  \n   ");
        $this->assertSame('', trim($result));
    }

    public function testSingleNewline(): void
    {
        $result = $this->converter->convert("\n");
        $this->assertSame('', trim($result));
    }

    // Edge cases: Unicode and special characters

    public function testUnicodeCharacters(): void
    {
        $djot = 'Hello 世界! 🎉 Привет мир';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('世界', $result);
        $this->assertStringContainsString('🎉', $result);
        $this->assertStringContainsString('Привет мир', $result);
    }

    public function testUnicodeInHeading(): void
    {
        $djot = '# 日本語の見出し';
        $result = $this->converter->convert($djot);

        // The visible heading text is unchanged; only the ID is made
        // ASCII-safe so it survives being shared as a URL fragment.
        $this->assertStringContainsString('<h1>日本語の見出し</h1>', $result);
        $this->assertStringNotContainsString('id="日本語の見出し"', $result);
        $this->assertMatchesRegularExpression('/<section id="[\x21-\x7E]+">/', $result);

        if (class_exists(Transliterator::class)) {
            // With ext-intl the CJK heading is romanized rather than dropped.
            $this->assertStringContainsString('<section id="ri-ben-yuno-jian-chushi">', $result);
        }
    }

    /**
     * The implicit-heading-reference pass (BlockParser, fresh tracker) and the
     * renderer must compute the same `s-N` fallback id, even when an explicit
     * non-heading id exists. Regression guard: a render-only dedup once made
     * the heading `s-2` while the reference still pointed at `#s-1`.
     */
    public function testGeneratedFallbackIdStaysConsistentWithImplicitReference(): void
    {
        $result = $this->converter->convert("{#s-1}\npara\n\n# !!!\n\n[!!!][]\n");

        $this->assertSame(
            1,
            preg_match('/<section id="(s-\d+)">/', $result, $section),
        );
        $this->assertStringContainsString('href="#' . $section[1] . '"', $result);
    }

    /**
     * Every explicit `{#id}` in the document (heading or not) must be
     * reserved up-front, so a heading whose auto-generated id would
     * otherwise collide takes the next free dedupe suffix instead of
     * silently emitting a duplicate id.
     *
     * Critical case: the explicit `{#Foo-Bar}` appears *after* the
     * heading whose text normalizes to `Foo-Bar`. Without an upfront
     * pre-pass, inline tracking only catches the explicit id once
     * encountered, and the heading rendered first wins the slot.
     */
    public function testExplicitIdsAreReservedBeforeAutoIds(): void
    {
        $result = $this->converter->convert("# Foo Bar\n\n{#Foo-Bar}\npara\n");

        $this->assertStringContainsString('<section id="Foo-Bar-1">', $result);
        $this->assertStringContainsString('<p id="Foo-Bar">', $result);
        // Pre-fix master emits two `Foo-Bar` ids — guard against the regression.
        $this->assertSame(1, substr_count($result, ' id="Foo-Bar"'));
    }

    /**
     * Once explicit ids are reserved up-front, the heading dedup loop must
     * also skip *suffix candidates* that are reserved — e.g. `# Foo`,
     * `{#Foo-1}`, `# Foo` should resolve the second heading to `Foo-2`,
     * not silently collide with the explicit `Foo-1`.
     */
    public function testHeadingDedupeSkipsReservedSuffixedIds(): void
    {
        $result = $this->converter->convert("# Foo\n\n{#Foo-1}\npara\n\n# Foo\n");

        $this->assertStringContainsString('<section id="Foo">', $result);
        $this->assertStringContainsString('<p id="Foo-1">', $result);
        $this->assertStringContainsString('<section id="Foo-2">', $result);
        $this->assertSame(1, substr_count($result, ' id="Foo-1"'));
    }

    /**
     * After reserving explicit ids, the implicit-reference pass and the
     * renderer must still produce matching anchors: a `[Heading][]` link
     * has to land on the (possibly-deduped) section id, not the original
     * line-based estimate. The post-parse rewrite re-targets refs.
     */
    public function testImplicitHeadingReferenceMatchesRenderedSectionId(): void
    {
        $result = $this->converter->convert("# Foo Bar\n\n{#Foo-Bar}\npara\n\n[Foo Bar][]\n");

        $this->assertSame(
            1,
            preg_match('/<section id="(Foo-Bar(?:-\d+)?)">/', $result, $section),
        );
        $this->assertStringContainsString('href="#' . $section[1] . '"', $result);
        $this->assertStringContainsString('<p id="Foo-Bar">', $result);
    }

    /**
     * Implicit `[Heading][]` links resolve to the *first* heading with that
     * text. Duplicate-heading auto-ids get suffixed (`Foo`, `Foo-1`, …) but
     * the reference must keep pointing at the original.
     */
    public function testImplicitHeadingReferencePrefersFirstDuplicate(): void
    {
        $result = $this->converter->convert("# Foo\n\n# Foo\n\n[Foo][]\n");

        $this->assertStringContainsString('href="#Foo"', $result);
        $this->assertStringNotContainsString('href="#Foo-1"', $result);
    }

    /**
     * After the post-parse rewrite recomputes heading ids, anchor-link
     * validation must see the renderer-visible ids (no false "Broken anchor"
     * warnings for valid links to deduped headings).
     */
    public function testAnchorValidationSeesPostRewriteHeadingIds(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("# Foo Bar\n\n{#Foo-Bar}\npara\n\n[x](#Foo-Bar-1)\n[y](#Foo-Bar)\n");

        $brokenAnchorWarnings = array_filter(
            $converter->getWarnings(),
            static fn ($w): bool => str_contains($w->getMessage(), 'Broken anchor link'),
        );

        $this->assertSame([], $brokenAnchorWarnings, 'no broken-anchor warnings for valid links');
    }

    /**
     * Parser state (incl. `headingReferenceLabels`) must reset between
     * conversions on the same `DjotConverter` instance — otherwise a label
     * marked as heading-owned in doc N silently lets doc N+1's rewrite
     * overwrite an explicit `[Foo]: url` reference.
     */
    public function testHeadingReferenceLabelsResetBetweenConversions(): void
    {
        $converter = new DjotConverter();

        // First conversion: heading registers "Foo" as a heading-owned label.
        $converter->convert("# Foo\n");

        // Second conversion: explicit `[Foo]: url` must win; the heading's
        // auto-id must not retarget the link to `#Foo`.
        $result = $converter->convert("[Foo][]\n\n[Foo]: https://example.com\n\n# Foo\n");

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringNotContainsString('href="#Foo"', $result);
    }

    /**
     * Implicit `[…][]` references whose label contains formatted inlines
     * (code, math, …) must still retarget to the correct heading id after
     * dedupe. The lookup key must include the inline content the same way
     * the heading's plain-text label does.
     */
    public function testImplicitReferenceWithCodeInLabelMatchesDedupedHeading(): void
    {
        $result = $this->converter->convert("# `Foo`\n\n{#Foo}\npara\n\n[`Foo`][]\n");

        $this->assertSame(
            1,
            preg_match('/<section id="(Foo(?:-\d+)?)">/', $result, $section),
        );
        $this->assertStringContainsString('href="#' . $section[1] . '"', $result);
    }

    /**
     * Reference-style images (`![alt][]`) share the implicit-reference
     * lookup with links; their `src` must also be retargeted to the
     * post-rewrite heading id, not the pre-dedupe estimate.
     */
    public function testImplicitReferenceImageRetargetsToDedupedHeading(): void
    {
        $result = $this->converter->convert("# Foo Bar\n\n{#Foo-Bar}\npara\n\n![Foo Bar][]\n");

        $this->assertSame(
            1,
            preg_match('/<section id="(Foo-Bar(?:-\d+)?)">/', $result, $section),
        );
        $this->assertStringContainsString('src="#' . $section[1] . '"', $result);
        $this->assertStringNotContainsString('src="#Foo-Bar"', $result);
    }

    public function testUnicodeInLink(): void
    {
        $djot = '[リンク](https://example.com/日本語)';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com/日本語"', $result);
        $this->assertStringContainsString('リンク</a>', $result);
    }

    public function testSpecialHtmlCharacters(): void
    {
        $djot = 'Test <script>alert("XSS")</script> & "quotes"';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    // Edge cases: Deeply nested structures

    public function testDeeplyNestedLists(): void
    {
        $djot = "- Level 1\n  - Level 2\n    - Level 3\n      - Level 4";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('Level 1', $result);
        $this->assertStringContainsString('Level 2', $result);
    }

    public function testDeeplyNestedBlockquotes(): void
    {
        $djot = "> Level 1\n>\n> > Level 2\n> >\n> > > Level 3";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Level 1', $result);
    }

    public function testDeeplyNestedDivs(): void
    {
        $djot = "::: outer\n::: middle\n::: inner\nContent\n:::\n:::\n:::";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="outer"', $result);
        $this->assertStringContainsString('class="middle"', $result);
        $this->assertStringContainsString('class="inner"', $result);
    }

    public function testNestedEmphasisAndStrong(): void
    {
        $djot = '*_strong emphasis_* and _*emphasis strong*_';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    // Edge cases: Inline code and backticks

    public function testMultipleBackticksInCode(): void
    {
        // Use 2 backticks to wrap content containing 1 backtick
        $djot = '`` `code` ``';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<code>', $result);
    }

    public function testCodeWithBacktickAtBoundary(): void
    {
        $djot = '`` ` ``';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<code>`</code>', $result);
    }

    public function testTripleBackticksInline(): void
    {
        $djot = '``` code ```';
        $result = $this->converter->convert($djot);

        // Triple backticks with closing on same line is inline code (official djot behavior)
        $this->assertStringContainsString('<code> code </code>', $result);
        $this->assertStringNotContainsString('<pre>', $result);
    }

    // Edge cases: Links and URLs

    public function testLinkWithParenthesesInUrl(): void
    {
        $djot = '[Wikipedia](https://en.wikipedia.org/wiki/Example_(disambiguation))';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://en.wikipedia.org/wiki/Example_(disambiguation)"', $result);
    }

    public function testLinkWithEscapedBracket(): void
    {
        $djot = '[Text with \] bracket](url)';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="url"', $result);
    }

    public function testEmptyLinkText(): void
    {
        $djot = '[](https://example.com)';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testLinkWithOnlyWhitespace(): void
    {
        $djot = '[   ](url)';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="url"', $result);
    }

    public function testAutolinkWithQueryString(): void
    {
        $djot = '<https://example.com/search?q=test&page=1>';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com/search?q=test&amp;page=1"', $result);
    }

    // Edge cases: Tables

    public function testTableWithEmptyCells(): void
    {
        $djot = "| A |  | C |\n|---|---|---|\n| 1 |  | 3 |";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<td></td>', $result);
    }

    public function testTableWithSingleColumn(): void
    {
        $djot = "| Header |\n|--------|\n| Cell |";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>Header</th>', $result);
    }

    public function testTableWithEscapedPipe(): void
    {
        $djot = "| A \\| B | C |\n|--------|---|\n| 1 \\| 2 | 3 |";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('A | B', $result);
        $this->assertStringContainsString('1 | 2', $result);
    }

    public function testTableWithMismatchedColumns(): void
    {
        // More cells in body than header
        $djot = "| A | B |\n|---|---|\n| 1 | 2 | 3 |";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<table>', $result);
    }

    // Edge cases: Code blocks

    public function testCodeBlockWithLongerClosingFence(): void
    {
        $djot = "```\ncode\n````";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString("<pre><code>code\n</code></pre>", $result);
    }

    public function testCodeBlockWithTildesAndBackticks(): void
    {
        $djot = "~~~\n```\ncode with backticks\n```\n~~~";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('```', $result);
        $this->assertStringContainsString('code with backticks', $result);
    }

    public function testCodeBlockWithEmptyContent(): void
    {
        $djot = "```\n```";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<pre><code></code></pre>', $result);
    }

    // Edge cases: Emphasis boundaries

    public function testEmphasisNotInMiddleOfWord(): void
    {
        // Underscores in the middle of words should create emphasis in Djot
        $djot = 'snake_case_name';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<em>case</em>', $result);
    }

    public function testStrongNotInMiddleOfWord(): void
    {
        $djot = 'some*thing*else';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<strong>thing</strong>', $result);
    }

    public function testMultipleConsecutiveDelimiters(): void
    {
        // In Djot, each * is a strong delimiter (unlike Markdown where ** = bold)
        // So ** opens two strong scopes, text, and ** closes them both
        $djot = 'Text **not strong** here';
        $result = $this->converter->convert($djot);

        // Result should be: Text <strong><strong>not strong</strong></strong> here
        $this->assertStringContainsString('<strong><strong>not strong</strong></strong>', $result);
    }

    /**
     * Test that empty emphasis delimiters are treated as literal text
     *
     * Regression test: __ and ___ should produce literal underscores, not empty <em> tags
     */
    public function testEmptyEmphasisIsLiteral(): void
    {
        $this->assertSame("<p>__</p>\n", $this->converter->convert('__'));
        $this->assertSame("<p>___</p>\n", $this->converter->convert('___'));
        $this->assertSame("<p>**</p>\n", $this->converter->convert('**'));
        // Note: *** at block level is a thematic break, so test inline
        $this->assertStringContainsString('***', $this->converter->convert('a***b'));
    }

    /**
     * Test that code spans inside emphasis don't break delimiter matching
     *
     * Regression test: The * inside `*` should be treated as code, not as emphasis closer
     */
    public function testCodeSpanInsideStrong(): void
    {
        $result = $this->converter->convert('*foo `*`*');

        $this->assertStringContainsString('<strong>foo <code>*</code></strong>', $result);
    }

    /**
     * Test that inline link URLs have newlines stripped
     *
     * Regression test: [link](url\nandurl) should produce urlandurl as the href
     */
    public function testInlineLinkUrlNewlineStripping(): void
    {
        $result = $this->converter->convert("[link](url\nandurl)");

        $this->assertStringContainsString('href="urlandurl"', $result);
    }

    // Edge cases: Escaping

    public function testEscapedSpecialCharacters(): void
    {
        $djot = '\* \_ \` \[ \] \{ \}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('* _ ` [ ] { }', $result);
    }

    public function testEscapedBackslash(): void
    {
        $djot = 'Test \\\\ here';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('\\', $result);
    }

    public function testEscapedBackslashBeforeDelimiter(): void
    {
        $djot = '\\\\*strong*';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('\\', $result);
        $this->assertStringContainsString('<strong>strong</strong>', $result);
    }

    public function testBackslashAtEndOfLine(): void
    {
        $djot = "Line with backslash\\\nNext line";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<br>', $result);
    }

    // Edge cases: Block attributes

    public function testBlockAttributesWithQuotedValues(): void
    {
        $djot = "{title=\"Hello World\" data-value=\"123\"}\n# Heading";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('title="Hello World"', $result);
        $this->assertStringContainsString('data-value="123"', $result);
    }

    public function testBlockAttributesWithSingleQuotedValues(): void
    {
        $djot = "{title='Single Quoted' data-info='some info'}\n# Heading";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('title="Single Quoted"', $result);
        $this->assertStringContainsString('data-info="some info"', $result);
    }

    public function testInlineAttributesWithQuotedValues(): void
    {
        $djot = '[styled text]{title="Hello World" data-value="test 123"}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('title="Hello World"', $result);
        $this->assertStringContainsString('data-value="test 123"', $result);
    }

    public function testMultipleBlockAttributeLines(): void
    {
        $djot = "{.class1}\n{.class2 #myid}\nParagraph";
        $result = $this->converter->convert($djot);

        // Second attribute line should apply to paragraph
        $this->assertStringContainsString('class2', $result);
        $this->assertStringContainsString('id="myid"', $result);
    }

    // Edge cases: Smart typography

    public function testSmartQuotesNested(): void
    {
        $djot = '"He said \'hello\' to me"';
        $result = $this->converter->convert($djot);

        // Should have curly quotes
        $this->assertStringContainsString("\u{201C}", $result); // Left double quote
        $this->assertStringContainsString("\u{201D}", $result); // Right double quote
        $this->assertStringContainsString("\u{2018}", $result); // Left single quote
        $this->assertStringContainsString("\u{2019}", $result); // Right single quote
    }

    /**
     * Test that consecutive opening quotes at line start are both openers
     *
     * Regression test: "'Shelob'" at line start should produce "'Shelob'"
     * not "'Shelob'" (where the single quote after double is a closer)
     */
    public function testSmartQuotesConsecutiveOpenersAtLineStart(): void
    {
        $this->converter->getHtmlRenderer()->setSoftBreakMode(SoftBreakMode::Newline);

        $djot = "\"Hello,\" said the spider.\n\"'Shelob' is my name.\"";
        $result = $this->converter->convert($djot);

        // Both " and ' at start of second line should be opening quotes
        // Expected: "'Shelob' (open double, open single, close single, close double)
        $this->assertStringContainsString("\u{201C}\u{2018}Shelob\u{2019}", $result);
    }

    public function testMultipleDashes(): void
    {
        $djot = 'Single - dash, double -- dash, triple --- dash, quad ---- dash';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString(' - ', $result); // Single stays
        $this->assertStringContainsString("\u{2013}", $result); // En-dash
        $this->assertStringContainsString("\u{2014}", $result); // Em-dash
    }

    public function testEllipsisInDifferentContexts(): void
    {
        $djot = 'Wait... and more... and...';
        $result = $this->converter->convert($djot);

        // All ... should become …
        $this->assertStringNotContainsString('...', $result);
        $this->assertStringContainsString("\u{2026}", $result);
    }

    // Edge cases: Footnotes

    public function testFootnoteWithSpecialCharacters(): void
    {
        $djot = "Text[^note-1]\n\n[^note-1]: Footnote with *bold* and `code`.";
        $result = $this->converter->convert($djot);

        // Footnotes now use numbered IDs per djot spec
        $this->assertStringContainsString('fnref1', $result);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<code>code</code>', $result);
    }

    public function testFootnoteWithMultipleParagraphs(): void
    {
        $djot = "Text[^long]\n\n[^long]: First paragraph.\n\n  Second paragraph.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('First paragraph', $result);
    }

    // Edge cases: Definition lists

    public function testDefinitionListWithMultipleTerms(): void
    {
        // Two separate terms, second with multiple definitions using `: +` continuation
        $djot = ": Term 1\n\n  Definition 1\n\n: Term 2\n\n  Definition 2a\n\n: +\n\n  Definition 2b";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<dt>Term 1</dt>', $result);
        $this->assertStringContainsString('<dt>Term 2</dt>', $result);
        $this->assertStringContainsString('Definition 2a', $result);
        $this->assertStringContainsString('Definition 2b', $result);
    }

    // Edge cases: Math

    public function testMathWithSpecialCharacters(): void
    {
        $djot = '$`x < y > z`$';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="math inline"', $result);
        // Math content should have escaped HTML entities
        $this->assertStringContainsString('x &lt; y &gt; z', $result);
    }

    public function testDisplayMathWithNewlines(): void
    {
        $djot = "$$`\n  x = y\n  y = z\n`$$";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="math display"', $result);
    }

    // Edge cases: Comments

    public function testCommentWithSpecialCharacters(): void
    {
        $djot = '{% Comment with <html> and & special chars %}';
        $result = $this->converter->convert($djot);

        $this->assertStringNotContainsString('Comment', $result);
        $this->assertStringNotContainsString('<html>', $result);
    }

    public function testCommentBetweenParagraphs(): void
    {
        $djot = "Para 1\n\n{% hidden %}\n\nPara 2";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<p>Para 1</p>', $result);
        $this->assertStringContainsString('<p>Para 2</p>', $result);
        $this->assertStringNotContainsString('hidden', $result);
    }

    /**
     * Inline comment at start of line should preserve text after it
     */
    public function testInlineCommentAtStartPreservesTextAfter(): void
    {
        $djot = '{% comment %} text after';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('text after', $result);
        $this->assertStringNotContainsString('comment', $result);
    }

    /**
     * Multiple inline comments on same line
     */
    public function testMultipleInlineComments(): void
    {
        $djot = '{% one %} text {% two %}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('text', $result);
        $this->assertStringNotContainsString('one', $result);
        $this->assertStringNotContainsString('two', $result);
    }

    /**
     * Inline comment in middle of text
     */
    public function testInlineCommentInMiddle(): void
    {
        $djot = 'before {% comment %} after';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('before', $result);
        $this->assertStringContainsString('after', $result);
        $this->assertStringNotContainsString('comment', $result);
    }

    /**
     * Inline comment should not strip {% %} inside code spans
     */
    public function testInlineCommentNotInCodeSpan(): void
    {
        $djot = '`{% not a comment %}`';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('{% not a comment %}', $result);
    }

    /**
     * Inline comment should not strip {% %} inside quoted attributes
     */
    public function testInlineCommentNotInQuotedAttribute(): void
    {
        $djot = '[text]{title="{% not %}"}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('{% not %}', $result);
    }

    // Edge cases: Raw content

    public function testRawBlockNonHtml(): void
    {
        $djot = "``` =latex\n\\frac{1}{2}\n```";
        $result = $this->converter->convert($djot);

        // Non-HTML raw blocks should not be rendered
        $this->assertStringNotContainsString('\\frac', $result);
    }

    public function testRawInlineNonHtml(): void
    {
        $djot = 'Text `\\frac{1}{2}`{=latex} more';
        $result = $this->converter->convert($djot);

        // Non-HTML raw inline should not be rendered
        $this->assertStringNotContainsString('\\frac', $result);
    }

    // Edge cases: Line blocks

    public function testLineBlockWithEmptyLines(): void
    {
        $djot = "| Line 1\n|\n| Line 3";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="line-block"', $result);
        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 3', $result);
    }

    // Edge cases: Spans

    public function testSpanWithEmptyContent(): void
    {
        $djot = '[]{.highlight}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<span class="highlight"></span>', $result);
    }

    public function testSpanWithNestedFormatting(): void
    {
        $djot = '[*bold* and _italic_]{.styled}';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="styled"', $result);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
    }

    // Edge cases: Images

    public function testImageWithEmptyAlt(): void
    {
        $djot = '![](image.png)';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('alt=""', $result);
        $this->assertStringContainsString('src="image.png"', $result);
    }

    public function testImageWithFormattingInAlt(): void
    {
        $djot = '![*Photo* of _something_](photo.jpg)';
        $result = $this->converter->convert($djot);

        // Alt text should be plain text extracted
        $this->assertStringContainsString('alt=', $result);
        $this->assertStringContainsString('src="photo.jpg"', $result);
    }

    // Edge cases: Mixed content

    public function testParagraphWithAllInlineTypes(): void
    {
        $djot = 'Text with _emphasis_, *strong*, `code`, [link](url), ^super^, ~sub~, {=high=}, {+ins+}, {-del-}.';
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('<em>emphasis</em>', $result);
        $this->assertStringContainsString('<strong>strong</strong>', $result);
        $this->assertStringContainsString('<code>code</code>', $result);
        $this->assertStringContainsString('<a href="url">link</a>', $result);
        $this->assertStringContainsString('<sup>super</sup>', $result);
        $this->assertStringContainsString('<sub>sub</sub>', $result);
        $this->assertStringContainsString('<mark>high</mark>', $result);
        $this->assertStringContainsString('<ins>ins</ins>', $result);
        $this->assertStringContainsString('<del>del</del>', $result);
    }

    public function testComplexNestedDocument(): void
    {
        $djot = <<<'DJOT'
# Complex Document

::: note
> This is a blockquote inside a div.

- List item 1
- List item 2
  - Nested item

| A | B |
|---|---|
| 1 | 2 |
:::

[^1]: Footnote content with *formatting*.

Reference[^1] here.
DJOT;

        $result = $this->converter->convert($djot);

        // Headings wrapped in section per djot spec
        $this->assertStringContainsString('<section id="Complex-Document">', $result);
        $this->assertStringContainsString('<h1>Complex Document</h1>', $result);
        $this->assertStringContainsString('class="note"', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('Footnote content', $result);
    }

    // Edge cases: Consecutive same-type elements

    public function testConsecutiveCodeBlocks(): void
    {
        $djot = "```\nblock 1\n```\n\n```\nblock 2\n```";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('block 1', $result);
        $this->assertStringContainsString('block 2', $result);
        $this->assertSame(2, substr_count($result, '<pre>'));
    }

    public function testConsecutiveBlockquotes(): void
    {
        $djot = "> Quote 1\n\n> Quote 2";
        $result = $this->converter->convert($djot);

        $this->assertSame(2, substr_count($result, '<blockquote>'));
    }

    // Edge cases: XHTML mode specifics

    public function testXhtmlHardBreak(): void
    {
        $converter = new DjotConverter(xhtml: true);
        $djot = "Line 1\\\nLine 2";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<br />', $result);
    }

    public function testXhtmlThematicBreak(): void
    {
        $converter = new DjotConverter(xhtml: true);
        $djot = "Above\n\n***\n\nBelow";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<hr />', $result);
    }

    // Edge cases: Reference links

    public function testCaseInsensitiveReferenceLink(): void
    {
        $djot = "[Example][REF]\n\n[ref]: https://example.com";
        $this->converter->convert($djot);

        // References are case-sensitive in Djot
        // This should produce a warning in warning mode, not a link
        $converter = new DjotConverter(warnings: true);
        $converter->convert($djot);
        $this->assertTrue($converter->hasWarnings());
    }

    public function testShortcutReferenceLink(): void
    {
        // [label][] syntax where label matches reference
        $djot = "[Example][]\n\n[Example]: https://example.com";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testUnclosedLinkEmphasisBoundary(): void
    {
        // Emphasis should NOT cross [text]( boundary when link is unclosed
        $djot = '[x_y](x_';
        $result = $this->converter->convert($djot);

        // Should be literal text, not emphasis
        $this->assertSame("<p>[x_y](x_</p>\n", $result);
    }

    public function testUnclosedLinkWithValidEmphasis(): void
    {
        // Emphasis entirely within (url) part should still work
        $djot = "[unclosed](hello *a\nb*";
        $result = $this->converter->convert($djot);

        // The *a\nb* is entirely in the url part, so emphasis applies
        $this->assertStringContainsString('<strong>a', $result);
        $this->assertStringContainsString('b</strong>', $result);
    }

    public function testReferenceDefinitionAttributes(): void
    {
        // Attributes before reference definition apply to the link
        $djot = "{title=foo}\n[ref]: /url\n\n[ref][]";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('title="foo"', $result);
        $this->assertStringContainsString('href="/url"', $result);
        // Attributes should be on the link, not the paragraph
        $this->assertStringNotContainsString('<p title=', $result);
    }

    public function testReferenceDefinitionAttributeOverride(): void
    {
        // Inline attributes override definition attributes
        $djot = "{title=foo}\n[ref]: /url\n\n[ref][]{title=bar}";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('title="bar"', $result);
        $this->assertStringNotContainsString('title="foo"', $result);
    }

    public function testAttributeOrderIdFirst(): void
    {
        // Attributes should be ordered: id first, then others in source order
        $djot = 'hi{#myid .myclass key="value"}';
        $result = $this->converter->convert($djot);

        // Check that id comes first, class and key follow in source order
        $this->assertMatchesRegularExpression('/id="myid".*class="myclass".*key="value"/', $result);
    }

    public function testAttributeOrderWithConsecutiveBlocks(): void
    {
        // From official attributes_16: consecutive attribute blocks, id first then source order
        $djot = "{#id}\n{key=val}\n{.foo .bar}\n{key=val2}\n{.baz}\n{#id2}\nOkay";
        $result = $this->converter->convert($djot);

        // Expected: id first, then key, then class (source order after id)
        $this->assertSame('<p id="id2" key="val2" class="foo bar baz">Okay</p>' . "\n", $result);
    }

    public function testAttributeOrderClassInMiddle(): void
    {
        // Class should stay in source order position, not be forced to second
        $djot = "{#id .class\n  style=\"color:red\"}\nA paragraph";
        $result = $this->converter->convert($djot);

        // Class comes before style because that's the source order
        $this->assertSame('<p id="id" class="class" style="color:red">A paragraph</p>' . "\n", $result);
    }

    public function testUnclosedBraceParagraphContinuation(): void
    {
        // Unclosed { means next line is continuation, not new block
        $djot = "text{a=x\n# not-a-heading";
        $result = $this->converter->convert($djot);

        // Should be single paragraph, not paragraph + heading
        $this->assertSame("<p>text{a=x\n# not-a-heading</p>\n", $result);
        $this->assertStringNotContainsString('<h1', $result);
    }

    public function testUnclosedBraceAtStartOfLine(): void
    {
        // Unclosed { at start of line also continues
        $djot = "{a=x\n# not-a-heading";
        $result = $this->converter->convert($djot);

        $this->assertSame("<p>{a=x\n# not-a-heading</p>\n", $result);
        $this->assertStringNotContainsString('<h1', $result);
    }

    // ==================== Nested List Edge Cases ====================

    public function testNestedListsWithIncrementingIndentation(): void
    {
        // Test 2 from official: blank lines introduce nested lists based on indentation
        $djot = "- one\n\n - two\n\n  - three";
        $result = $this->converter->convert($djot);

        // Should produce three nesting levels (tight)
        $expected = "<ul>\n<li>\none\n<ul>\n<li>\ntwo\n<ul>\n<li>\nthree\n</li>\n</ul>\n</li>\n</ul>\n</li>\n</ul>\n";
        $this->assertSame($expected, $result);
    }

    public function testListLooseWithMultipleParagraphs(): void
    {
        // Test 3 from official: blank line before indented text makes list loose
        $djot = "- one\n  and\n\n  another paragraph\n\n  - a list\n\n- two";
        $result = $this->converter->convert($djot);

        // Should have <p> tags around content (loose list)
        $this->assertStringContainsString('<p>one', $result);
        $this->assertStringContainsString('<p>another paragraph</p>', $result);
        $this->assertStringContainsString('<p>two</p>', $result);
    }

    public function testListTightWithIndentedListLikeContinuation(): void
    {
        // Test 7 from official: "- b" is literal text when no blank line precedes
        $djot = "- a\n  - b\n\n  - c\n- d";
        $result = $this->converter->convert($djot);

        // "- b" should be literal text, "- c" after blank should be nested list
        $expected = "<ul>\n<li>\na\n- b\n<ul>\n<li>\nc\n</li>\n</ul>\n</li>\n<li>\nd\n</li>\n</ul>\n";
        $this->assertSame($expected, $result);
    }

    public function testListTightWithNestedContentAndBlankBeforeSibling(): void
    {
        // Test 8 from official: blank before sibling within nested content doesn't make outer loose
        $djot = "- a\n  - b\n\n  - c\n\n- d";
        $result = $this->converter->convert($djot);

        // Should be tight (no <p> tags around a and d)
        $expected = "<ul>\n<li>\na\n- b\n<ul>\n<li>\nc\n</li>\n</ul>\n</li>\n<li>\nd\n</li>\n</ul>\n";
        $this->assertSame($expected, $result);
    }

    public function testLazyListContinuationAfterNestedContent(): void
    {
        // Test 12 from official: lazy continuation at base indent continues nested list item
        $djot = "- a\n\n  * b\ncd";
        $result = $this->converter->convert($djot);

        // "cd" should be part of nested list item "b"
        $expected = "<ul>\n<li>\na\n<ul>\n<li>\nb\ncd\n</li>\n</ul>\n</li>\n</ul>\n";
        $this->assertSame($expected, $result);
    }

    public function testNestedListTightWithMultipleItems(): void
    {
        // Tests 10 and 11 from official: multiple nested items, tight
        $djot = "- a\n\n  - b\n  - c\n- d";
        $result = $this->converter->convert($djot);

        // Should be tight
        $expected = "<ul>\n<li>\na\n<ul>\n<li>\nb\n</li>\n<li>\nc\n</li>\n</ul>\n</li>\n<li>\nd\n</li>\n</ul>\n";
        $this->assertSame($expected, $result);
    }

    // ==================== Paragraph Newline Handling ====================

    public function testParagraphPreservesTrailingSoftBreak(): void
    {
        // From official attributes_12: trailing softbreak should be preserved
        $djot = "After {#id} space\n{.class}";
        $result = $this->converter->convert($djot);

        // The {.class} becomes a block attribute, leaving "After  space\n" in paragraph
        // The newline before </p> should be preserved
        $this->assertSame("<p>After  space\n</p>\n", $result);
    }

    public function testParagraphTrimsTrailingSpacesNotNewlines(): void
    {
        // Trailing spaces should be trimmed, but newlines preserved
        $djot = 'hello world   ';
        $result = $this->converter->convert($djot);

        // Trailing spaces trimmed
        $this->assertSame("<p>hello world</p>\n", $result);
    }

    /**
     * Leading whitespace in paragraphs should be stripped (matching JS reference)
     */
    public function testParagraphStripsLeadingWhitespace(): void
    {
        // Single space
        $this->assertSame("<p>text</p>\n", $this->converter->convert(' text'));

        // Multiple spaces
        $this->assertSame("<p>text</p>\n", $this->converter->convert('   text'));

        // Tab
        $this->assertSame("<p>text</p>\n", $this->converter->convert("\ttext"));

        // Mixed spaces and tabs
        $this->assertSame("<p>text</p>\n", $this->converter->convert("  \t text"));
    }

    /**
     * Leading whitespace on continuation lines should also be stripped
     */
    public function testParagraphStripsLeadingWhitespaceOnContinuation(): void
    {
        // First line has leading space, second doesn't
        $result = $this->converter->convert("   first line\nsecond line");
        $this->assertSame("<p>first line\nsecond line</p>\n", $result);

        // Both lines have leading whitespace
        $result = $this->converter->convert("   first line\n   second line");
        $this->assertSame("<p>first line\nsecond line</p>\n", $result);

        // Tab on continuation
        $result = $this->converter->convert("first\n\tsecond");
        $this->assertSame("<p>first\nsecond</p>\n", $result);
    }

    // ==================== Raw Inline with Mixed Attributes ====================

    public function testRawInlineWithMixedAttributesIsLiteral(): void
    {
        // From official raw_2: {=html #id} is NOT valid raw syntax, should be literal text
        $djot = "`<b>foo</b>`{=html #id}\n```";
        $result = $this->converter->convert($djot);

        // The code span is normal code (not raw), {=html #id} is literal text
        // The ``` on second line is inline code (empty)
        $expected = "<p><code>&lt;b&gt;foo&lt;/b&gt;</code>{=html #id}\n<code></code></p>\n";
        $this->assertSame($expected, $result);
    }

    public function testRawInlineOnlyWithPureFormat(): void
    {
        // Valid raw inline: only {=format} with no other attributes
        $djot = '`<a>`{=html}';
        $result = $this->converter->convert($djot);

        // Should output raw HTML
        $this->assertSame("<p><a></p>\n", $result);
    }

    // ==================== Bare Backticks as Inline Code ====================

    public function testBareBackticksAtEndOfParagraphIsInlineCode(): void
    {
        // Triple backticks at end of paragraph with no closing = inline code
        $djot = "Some text\n```";
        $result = $this->converter->convert($djot);

        // Should be one paragraph with empty inline code at end
        $this->assertSame("<p>Some text\n<code></code></p>\n", $result);
    }

    public function testBareBackticksAloneIsInlineCode(): void
    {
        // Just triple backticks with nothing else = inline code
        $djot = 'text ```';
        $result = $this->converter->convert($djot);

        // Should be inline code
        $this->assertSame("<p>text <code></code></p>\n", $result);
    }

    public function testUnclosedBackticksWithInfoStringIsInlineCode(): void
    {
        // From official code_blocks: unclosed ``` with info string = inline code
        $djot = '``` not a code block';
        $result = $this->converter->convert($djot);

        // Should be inline code in a paragraph
        $this->assertSame("<p><code> not a code block</code></p>\n", $result);
    }

    public function testBackticksWithClosingFenceIsCodeBlock(): void
    {
        // Triple backticks with proper closing = code block
        $djot = "``` python\nx = y + 3\n```";
        $result = $this->converter->convert($djot);

        // Should be code block with language
        $this->assertStringContainsString('<pre><code class="language-python">', $result);
        $this->assertStringContainsString('x = y + 3', $result);
    }

    /**
     * Test that multiple references to the same footnote get unique IDs
     *
     * Fix for GitHub issue jgm/djot#348: Multiple calls to the same note
     * should generate unique HTML-compliant IDs with suffixes.
     */
    public function testMultipleFootnoteReferencesGetUniqueIds(): void
    {
        $djot = <<<'DJOT'
First ref[^note].

Second ref[^note].

Third ref[^note].

[^note]: The footnote.
DJOT;

        $result = $this->converter->convert($djot);

        // Each reference should have a unique ID
        $this->assertStringContainsString('id="fnref1"', $result);
        $this->assertStringContainsString('id="fnref1-2"', $result);
        $this->assertStringContainsString('id="fnref1-3"', $result);

        // All should link to the same footnote
        $this->assertSame(3, substr_count($result, 'href="#fn1"'));

        // Footnote should have multiple backlinks
        $this->assertStringContainsString('href="#fnref1"', $result);
        $this->assertStringContainsString('href="#fnref1-2"', $result);
        $this->assertStringContainsString('href="#fnref1-3"', $result);

        // Single footnote (no duplicates)
        $this->assertSame(1, substr_count($result, 'id="fn1"'));
    }

    public function testSingleFootnoteReferenceNoSuffix(): void
    {
        $djot = <<<'DJOT'
Single ref[^note].

[^note]: The footnote.
DJOT;

        $result = $this->converter->convert($djot);

        // Single reference - no suffix needed
        $this->assertStringContainsString('id="fnref1"', $result);
        $this->assertStringNotContainsString('fnref1-', $result);

        // Simple backlink without numbering
        $this->assertStringContainsString('href="#fnref1"', $result);
        $this->assertStringNotContainsString('<sup>1</sup></a> <a', $result);
    }

    public function testWarningCategory(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('reference', $warnings[0]->getCategory());
    }

    public function testWarningSuggestion(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][myref]');

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertNotNull($warnings[0]->getSuggestion());
        $this->assertStringContainsString('[myref]:', $warnings[0]->getSuggestion());
    }

    public function testWarningToArrayIncludesNewFields(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $warnings = $converter->getWarnings();
        $array = $warnings[0]->toArray();

        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('suggestion', $array);
        $this->assertSame('reference', $array['category']);
    }

    public function testWarningToStringIncludesCategory(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[Missing][ref]');

        $warnings = $converter->getWarnings();
        $string = (string)$warnings[0];

        $this->assertStringContainsString('[reference]', $string);
    }

    public function testUnusedReferenceWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("Some text.\n\n[unused]: https://example.com");

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Reference 'unused' defined but never used", $warnings[0]->getMessage());
        $this->assertSame('reference', $warnings[0]->getCategory());
    }

    public function testUnusedNumericReferenceWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("Some text.\n\n[1]: https://example.com");

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Reference '1' defined but never used", $warnings[0]->getMessage());
        $this->assertSame('reference', $warnings[0]->getCategory());
    }

    public function testNoUnusedWarningForUsedReference(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[Link text][myref]\n\n[myref]: https://example.com");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testNoUnusedWarningForHeadingAutoReferences(): void
    {
        $converter = new DjotConverter(warnings: true);
        // Heading creates auto-reference but we don't warn if unused
        $converter->convert("# My Heading\n\nSome text without link.");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testMultipleReferenceWarningTypes(): void
    {
        $converter = new DjotConverter(warnings: true);
        $djot = <<<'DJOT'
[Link][undefined]

[unused]: https://example.com
DJOT;
        $converter->convert($djot);

        $warnings = $converter->getWarnings();
        $this->assertCount(2, $warnings);

        $messages = array_map(fn ($w) => $w->getMessage(), $warnings);
        $undefinedFound = false;
        $unusedFound = false;
        foreach ($messages as $msg) {
            if (str_contains($msg, 'Undefined')) {
                $undefinedFound = true;
            }
            if (str_contains($msg, 'never used')) {
                $unusedFound = true;
            }
        }
        $this->assertTrue($undefinedFound, 'Expected undefined reference warning');
        $this->assertTrue($unusedFound, 'Expected unused reference warning');
    }

    public function testBrokenAnchorLinkInlineWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[click here](#nonexistent)');

        $this->assertTrue($converter->hasWarnings());
        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("Broken anchor link '#nonexistent'", $warnings[0]->getMessage());
        $this->assertSame('anchor', $warnings[0]->getCategory());
    }

    public function testBrokenAnchorLinkViaReferenceWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[click here][ref]\n\n[ref]: #nonexistent");

        $warnings = $converter->getWarnings();
        $anchorWarnings = array_filter(
            $warnings,
            fn ($w) => $w->getCategory() === 'anchor',
        );
        $this->assertCount(1, $anchorWarnings);
        $this->assertStringContainsString('#nonexistent', array_values($anchorWarnings)[0]->getMessage());
    }

    public function testValidAnchorLinkToHeadingNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("# My Heading\n\n[link](#My-Heading)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testValidAnchorLinkToExplicitIdNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("{#custom-id}\n# Heading\n\n[link](#custom-id)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testValidAnchorLinkToExplicitDivIdNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("{#my-section}\n::: note\nContent\n:::\n\n[link](#my-section)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testValidAnchorLinkViaHeadingReferenceNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("# Introduction\n\n[Introduction][]");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testValidAnchorLinkToPunctuationHeadingNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("# Hello, world!\n\n[link](#Hello-world)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testValidAnchorLinkToCodeHeadingNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("# `code()` heading\n\n[link](#code-heading)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testHeadingReferenceUsesRendererCompatibleIdForPunctuationHeading(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("# Hello, world!\n\n[Hello, world!][]");

        $this->assertStringContainsString('id="Hello-world"', $html);
        $this->assertStringContainsString('href="#Hello-world"', $html);
    }

    public function testHeadingReferenceUsesRendererCompatibleIdForCodeHeading(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("# `code()` heading\n\n[`code()` heading][]");

        $this->assertStringContainsString('id="code-heading"', $html);
        $this->assertStringContainsString('href="#code-heading"', $html);
    }

    public function testNoAnchorWarningWithoutWarningsEnabled(): void
    {
        $converter = new DjotConverter(warnings: false);
        $converter->convert('[click here](#nonexistent)');

        $this->assertFalse($converter->hasWarnings());
    }

    public function testMultipleBrokenAnchorLinks(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[one](#missing1)\n\n[two](#missing2)");

        $warnings = $converter->getWarnings();
        $anchorWarnings = array_filter(
            $warnings,
            fn ($w) => $w->getCategory() === 'anchor',
        );
        $this->assertCount(2, $anchorWarnings);
    }

    public function testExternalUrlWithFragmentNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[link](https://example.com/page#section)');

        $this->assertFalse($converter->hasWarnings());
    }

    public function testEmptyFragmentNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('[link](#)');

        $this->assertFalse($converter->hasWarnings());
    }

    public function testBrokenAnchorWithValidHeadings(): void
    {
        $converter = new DjotConverter(warnings: true);
        $djot = <<<'DJOT'
# Valid Heading

[valid link](#Valid-Heading)

[broken link](#nonexistent)
DJOT;
        $converter->convert($djot);

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('#nonexistent', $warnings[0]->getMessage());
    }

    public function testValidAnchorLinkToSpanIdNoWarning(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[target]{#my-target}\n\n[link](#my-target)");

        $this->assertFalse($converter->hasWarnings());
    }

    public function testExtensionsWithPlainTextRenderer(): void
    {
        // Extensions should gracefully degrade with non-HTML renderers
        $converter = DjotConverter::plainText();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
# Main Title

::: tabs
::: tab
### Tab 1

Content 1
:::

::: tab
### Tab 2

Content 2
:::
:::

Some paragraph.
DJOT;

        $result = $converter->convert($djot);

        // Should render content without HTML-specific transforms
        $this->assertStringContainsString('Main Title', $result);
        $this->assertStringContainsString('Tab 1', $result);
        $this->assertStringContainsString('Content 1', $result);
        $this->assertStringContainsString('Some paragraph', $result);
        // Should NOT contain HTML
        $this->assertStringNotContainsString('<', $result);
    }

    public function testNamedConstructors(): void
    {
        $djot = "# Hello\n\nWorld";

        // markdown()
        $converter = DjotConverter::markdown();
        $result = $converter->convert($djot);
        $this->assertStringContainsString('# Hello', $result);
        $this->assertStringNotContainsString('<h1>', $result);

        // plainText()
        $converter = DjotConverter::plainText();
        $result = $converter->convert($djot);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringNotContainsString('#', $result);
        $this->assertStringNotContainsString('<', $result);

        // ansi()
        $converter = DjotConverter::ansi();
        $result = $converter->convert($djot);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testCreateWithCustomRenderer(): void
    {
        $converter = DjotConverter::create(
            renderer: new MarkdownRenderer(),
        );

        $result = $converter->convert("# Hello\n\n*bold*");

        $this->assertStringContainsString('# Hello', $result);
        $this->assertStringContainsString('**bold**', $result);
    }

    public function testOnOffNoOpWithNonHtmlRenderer(): void
    {
        $converter = DjotConverter::plainText();

        // Should not throw, just return $this
        $result = $converter->on('render.paragraph', function () {
        });
        $this->assertSame($converter, $result);

        $result = $converter->off('render.paragraph');
        $this->assertSame($converter, $result);

        $result = $converter->setSafeMode(true);
        $this->assertSame($converter, $result);
    }
}
