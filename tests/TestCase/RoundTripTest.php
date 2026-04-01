<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use Djot\Extension\AdmonitionExtension;
use Djot\Extension\CodeGroupExtension;
use Djot\Extension\MermaidExtension;
use Djot\Extension\TabsExtension;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive round-trip tests for Djot -> HTML -> Djot conversion
 *
 * These tests verify that content survives the round-trip through HTML
 * using the data-djot-src attribute for lossless conversion.
 */
class RoundTripTest extends TestCase
{
    private DjotConverter $converter;

    private HtmlToDjot $htmlToDjot;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter(roundTripMode: true);
        $this->converter->addExtension(new CodeGroupExtension());
        $this->converter->addExtension(new TabsExtension());
        $this->converter->addExtension(new MermaidExtension());
        $this->converter->addExtension(new AdmonitionExtension());
        $this->htmlToDjot = new HtmlToDjot();
    }

    /**
     * Helper to test round-trip conversion
     */
    private function assertRoundTrip(string $djot, string $message = ''): void
    {
        $html = $this->converter->convert($djot);
        $back = trim($this->htmlToDjot->convert($html));
        $this->assertSame(trim($djot), $back, $message ?: 'Round-trip failed');
    }

    /**
     * Helper to verify data-djot-src is present
     */
    private function assertHasDjotSrc(string $html, string $message = ''): void
    {
        $this->assertStringContainsString('data-djot-src=', $html, $message ?: 'Missing data-djot-src');
    }

    // =========================================================================
    // Code Blocks
    // =========================================================================

    public function testSimpleCodeBlock(): void
    {
        $djot = <<<'DJOT'
``` php
echo "Hello";
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockWithBackticks(): void
    {
        $djot = <<<'DJOT'
```` markdown
Here is a code block:

```javascript
console.log("Hello");
```

End of example.
````
DJOT;
        $html = $this->converter->convert($djot);
        $this->assertHasDjotSrc($html);
        $this->assertStringContainsString('````', $html, 'Should preserve 4-backtick fence in data-djot-src');
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockWithManyBackticks(): void
    {
        $djot = <<<'DJOT'
`````` text
Here are some backticks: ``` and ```` and `````
``````
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockWithAttributes(): void
    {
        $djot = <<<'DJOT'
{#my-code .highlight}
``` python
def hello():
    print("world")
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockNoLanguage(): void
    {
        $djot = <<<'DJOT'
```
plain text here
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Mermaid Diagrams
    // =========================================================================

    public function testMermaidFlowchart(): void
    {
        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A[Start] --> B{Decision};
    B -->|Yes| C[OK];
    B -->|No| D[End];
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMermaidWithSpecialChars(): void
    {
        $djot = <<<'DJOT'
``` mermaid
graph LR
    A["Input: <data>"] --> B["Process & Transform"]
    B --> C["Output"]
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMermaidSequenceDiagram(): void
    {
        $djot = <<<'DJOT'
``` mermaid
sequenceDiagram
    Alice->>Bob: Hello
    Bob-->>Alice: Hi
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Code Groups
    // =========================================================================

    public function testCodeGroupBasic(): void
    {
        $djot = <<<'DJOT'
::: code-group
``` php
echo "PHP";
```

``` javascript
console.log("JS");
```
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeGroupWithLabels(): void
    {
        $djot = <<<'DJOT'
::: code-group
``` php [Composer]
composer require package
```

``` bash [NPM]
npm install package
```
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeGroupWithBackticksInCode(): void
    {
        $djot = <<<'DJOT'
::: code-group
```` markdown [Example]
Here is code:

```js
test();
```
````
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeGroupWithAttributes(): void
    {
        $djot = <<<'DJOT'
{#install-options .wide}
::: code-group
``` bash
echo "test"
```
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Tabs
    // =========================================================================

    public function testTabsWithHeadings(): void
    {
        // Note: blank line between tabs is normalized during round-trip
        $djot = <<<'DJOT'
:::: tabs

::: tab
### First Tab

Content here.
:::
::: tab
### Second Tab

More content.
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTabsWithLabelAttribute(): void
    {
        // Note: blank line between tabs is normalized during round-trip
        $djot = <<<'DJOT'
:::: tabs

{label=First}
::: tab
Content for first tab.
:::
{label=Second}
::: tab
Content for second tab.
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTabsWithCodeBlocks(): void
    {
        // Note: blank line between tabs is normalized during round-trip
        $djot = <<<'DJOT'
:::: tabs

::: tab
### Sync

``` php
$result = fetch();
```
:::
::: tab
### Async

``` php
$promise = fetchAsync();
```
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTabsWithNestedCodeGroup(): void
    {
        $djot = <<<'DJOT'
:::: tabs

{label=Install}
::: tab
::: code-group
``` php
composer require pkg
```

``` bash
npm install pkg
```
:::
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTabsWithRichContent(): void
    {
        $djot = <<<'DJOT'
{#wrapper .outer}
:::: tabs

{#first .alpha label="First tab" selected}
::: tab
Text with *bold*, _em_, `code`, ![alt](img.png), and [link](https://example.com).

> quote

1. one
2. two
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTabsWithTable(): void
    {
        // Note: table column widths may be normalized during round-trip
        $djot = <<<'DJOT'
:::: tabs

::: tab
### Config

| Option | Value |
|--------|-------|
| debug | true |
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Combined / Complex
    // =========================================================================

    public function testMixedContent(): void
    {
        $djot = <<<'DJOT'
# Heading

Paragraph with *bold* and _italic_.

``` php
echo "code";
```

::: code-group
``` js
console.log(1);
```
:::

``` mermaid
graph TD;
    A --> B;
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testNestedStructures(): void
    {
        // Note: Parser has limitations with multiple tabs containing nested code-groups
        // when both use ::: fences. This test uses a single tab with nested code-group.
        $djot = <<<'DJOT'
:::: tabs

{label=Install}
::: tab
Introduction text.

::: code-group
``` php [PHP]
$x = 1;
```

``` js [JS]
let x = 1;
```
:::
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleTabsWithMermaid(): void
    {
        // Test multiple tabs where one has mermaid (no nested code-group)
        $djot = <<<'DJOT'
:::: tabs

::: tab
### Code

``` php
$x = 1;
```
:::
::: tab
### Diagram

``` mermaid
graph LR
    A --> B
```
:::
::::
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testEmptyCodeBlock(): void
    {
        $djot = <<<'DJOT'
```

```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockWithOnlyWhitespace(): void
    {
        $djot = <<<'DJOT'
```

```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCodeBlockWithTrailingSpaces(): void
    {
        $djot = <<<'DJOT'
``` php
echo "test";
```
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleCodeBlocksWithBackticks(): void
    {
        $djot = <<<'DJOT'
First block:

```` md
```
nested
```
````

Second block:

````` text
````
more nested
````
`````
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Verify data-djot-src presence
    // =========================================================================

    public function testCodeBlockHasDjotSrc(): void
    {
        $djot = "``` php\necho 1;\n```";
        $html = $this->converter->convert($djot);
        $this->assertHasDjotSrc($html, 'Code block should have data-djot-src');
    }

    public function testMermaidHasDjotSrc(): void
    {
        $djot = "``` mermaid\ngraph TD;\n```";
        $html = $this->converter->convert($djot);
        $this->assertHasDjotSrc($html, 'Mermaid should have data-djot-src');
    }

    public function testCodeGroupHasDjotSrc(): void
    {
        $djot = "::: code-group\n``` php\ntest\n```\n:::";
        $html = $this->converter->convert($djot);
        $this->assertHasDjotSrc($html, 'Code group should have data-djot-src');
    }

    public function testTabsHasDjotSrc(): void
    {
        $djot = ":::: tabs\n::: tab\n### Tab\nContent\n:::\n::::";
        $html = $this->converter->convert($djot);
        $this->assertHasDjotSrc($html, 'Tabs should have data-djot-src');
    }

    // =========================================================================
    // Headings with Custom IDs
    // =========================================================================

    public function testHeadingWithCustomId(): void
    {
        $djot = <<<'DJOT'
{#my-custom-id}
# Heading
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testHeadingWithCustomIdAndClass(): void
    {
        $djot = <<<'DJOT'
{#special .fancy}
## Styled Heading
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleHeadingsWithCustomIds(): void
    {
        $djot = <<<'DJOT'
{#intro}
# Introduction

Some text.

{#methods}
## Methods
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testHeadingWithoutCustomId(): void
    {
        // Auto-generated IDs should not be preserved
        $djot = '# Simple Heading';
        $html = $this->converter->convert($djot);
        $back = trim($this->htmlToDjot->convert($html));
        // Should NOT have ID attribute in round-trip
        $this->assertSame($djot, $back);
    }

    // =========================================================================
    // Inline Code with Backticks
    // =========================================================================

    public function testInlineCodeWithBackticks(): void
    {
        $djot = 'Use `` `backtick` `` in code.';
        $this->assertRoundTrip($djot);
    }

    public function testInlineCodeWithMultipleBackticks(): void
    {
        $djot = 'Show ``` ``double`` ``` ticks.';
        $this->assertRoundTrip($djot);
    }

    public function testInlineCodeStartingWithBacktick(): void
    {
        $djot = 'Use `` `start`` end.';
        $this->assertRoundTrip($djot);
    }

    public function testInlineCodeEndingWithBacktick(): void
    {
        $djot = 'Use ``end` `` done.';
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Tables with Various Formats
    // =========================================================================

    public function testTableWithMinimalSeparator(): void
    {
        $djot = <<<'DJOT'
| A | B |
|--|--|
| 1 | 2 |
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTableWithAlignment(): void
    {
        $djot = <<<'DJOT'
| Left | Center | Right |
|:--|:--:|--:|
| L | C | R |
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTableWithWiderSeparators(): void
    {
        $djot = <<<'DJOT'
| Header 1 | Header 2 |
|----------|----------|
| Data 1 | Data 2 |
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testTableWithMixedWidths(): void
    {
        $djot = <<<'DJOT'
| A | Longer |
|--|-------|
| 1 | Data |
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Nested Lists (with correct Djot syntax)
    // =========================================================================

    public function testNestedListsWithBlankLines(): void
    {
        $djot = <<<'DJOT'
- Parent

  - Child

    - Grandchild
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testNestedOrderedLists(): void
    {
        // Djot nested lists use 2-space indentation per level
        // Blank lines between items may not be preserved (tight vs loose list)
        $djot = <<<'DJOT'
1. First

  1. Nested first
  2. Nested second
2. Second
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Definition Lists (with correct Djot syntax)
    // =========================================================================

    public function testDefinitionList(): void
    {
        $djot = <<<'DJOT'
: Term

  Definition text here
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testDefinitionListMultipleTerms(): void
    {
        $djot = <<<'DJOT'
: First Term

  First definition

: Second Term

  Second definition
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Reference Links
    // =========================================================================

    public function testReferenceLinkExplicit(): void
    {
        $djot = <<<'DJOT'
See [the documentation][docs] for more info.

[docs]: https://example.com/docs
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testReferenceLinkCollapsed(): void
    {
        $djot = <<<'DJOT'
Check [Example][] for details.

[Example]: https://example.com
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testReferenceImageExplicit(): void
    {
        $djot = <<<'DJOT'
![Alt text][logo]

[logo]: https://example.com/logo.png
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testReferenceImageCollapsed(): void
    {
        $djot = <<<'DJOT'
![My Logo][]

[My Logo]: https://example.com/my-logo.png
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleReferencesSharedDefinition(): void
    {
        $djot = <<<'DJOT'
See [here][site] and [there][site] for more.

[site]: https://example.com
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMixedReferencesAndInlineLinks(): void
    {
        $djot = <<<'DJOT'
Check [Ref Link][ref] and [inline](https://inline.com).

[ref]: https://ref.com
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Autolinks
    // =========================================================================

    public function testAutolinkUrl(): void
    {
        $djot = 'Visit <https://example.com> for more info.';
        $this->assertRoundTrip($djot);
    }

    public function testAutolinkEmail(): void
    {
        $djot = 'Contact <user@example.com> for help.';
        $this->assertRoundTrip($djot);
    }

    public function testAutolinkWithAttributes(): void
    {
        $djot = 'See <https://example.com>{.external} for details.';
        $this->assertRoundTrip($djot);
    }

    public function testMixedAutolinksAndRegularLinks(): void
    {
        $djot = 'Visit <https://auto.com> or [click here](https://regular.com).';
        $this->assertRoundTrip($djot);
    }

    public function testAutolinkWithScheme(): void
    {
        $djot = 'Use <ftp://files.example.com> to download.';
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Footnotes
    // =========================================================================

    public function testSimpleFootnote(): void
    {
        $djot = <<<'DJOT'
Text[^1].

[^1]: Footnote content.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testNamedFootnote(): void
    {
        $djot = <<<'DJOT'
Text[^note].

[^note]: Named footnote.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleFootnotes(): void
    {
        $djot = <<<'DJOT'
First[^1] and second[^2].

[^1]: First note.
[^2]: Second note.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testFootnoteWithFormatting(): void
    {
        $djot = <<<'DJOT'
Text[^1].

[^1]: Footnote with *emphasis* and `code`.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testFootnoteWithLink(): void
    {
        $djot = <<<'DJOT'
Text[^1].

[^1]: Footnote with [link](http://example.com).
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Admonitions (via AdmonitionExtension)
    // =========================================================================

    public function testSimpleAdmonitionNote(): void
    {
        $djot = <<<'DJOT'
::: note
Content here.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAdmonitionWarning(): void
    {
        $djot = <<<'DJOT'
::: warning
Warning content.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAdmonitionWithMultipleParagraphs(): void
    {
        $djot = <<<'DJOT'
::: note
First paragraph.

Second paragraph.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAdmonitionWithFormatting(): void
    {
        $djot = <<<'DJOT'
::: note
This is *important* text.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAdmonitionWithCustomTitle(): void
    {
        $djot = <<<'DJOT'
{title="My Custom Title"}
::: note
Content with custom title.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCollapsibleAdmonition(): void
    {
        $djot = <<<'DJOT'
{collapsible}
::: tip
Collapsible content.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCollapsibleAdmonitionOpen(): void
    {
        $djot = <<<'DJOT'
{collapsible=open}
::: danger
Expanded by default.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testCollapsibleAdmonitionWithTitle(): void
    {
        $djot = <<<'DJOT'
{collapsible title="Click me"}
::: note
Hidden content.
:::
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Line Blocks
    // =========================================================================

    public function testSimpleLineBlock(): void
    {
        $djot = <<<'DJOT'
| Line one
| Line two
| Line three
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testLineBlockWithFormatting(): void
    {
        $djot = <<<'DJOT'
| This is *strong*
| And _emphasis_
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testLineBlockWithAttributes(): void
    {
        $djot = <<<'DJOT'
{.poem}
| Roses are red
| Violets are blue
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Raw Inline
    // =========================================================================

    public function testRawInlineHtml(): void
    {
        $djot = 'Text `<br>`{=html} more.';
        $this->assertRoundTrip($djot);
    }

    public function testRawInlineHtmlComplex(): void
    {
        $djot = 'Insert `<span class="red">colored</span>`{=html} text.';
        $this->assertRoundTrip($djot);
    }

    public function testRawInlineNonHtml(): void
    {
        // Non-HTML formats are preserved in round-trip mode
        $djot = 'LaTeX: `\alpha`{=tex} formula.';
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Abbreviation Definitions
    // =========================================================================

    public function testSingleAbbreviation(): void
    {
        $djot = <<<'DJOT'
*[HTML]: Hypertext Markup Language

The HTML spec defines the standard.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testMultipleAbbreviations(): void
    {
        $djot = <<<'DJOT'
*[HTML]: Hypertext Markup Language
*[CSS]: Cascading Style Sheets

HTML and CSS are web standards.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAbbreviationWithMultipleOccurrences(): void
    {
        $djot = <<<'DJOT'
*[API]: Application Programming Interface

The API is well documented. Use the API wisely.
DJOT;
        $this->assertRoundTrip($djot);
    }

    // =========================================================================
    // Escaped Characters
    // =========================================================================

    public function testEscapedAsterisks(): void
    {
        $djot = 'This is \*not bold\* text.';
        $this->assertRoundTrip($djot);
    }

    public function testEscapedUnderscore(): void
    {
        $djot = 'This is \_not italic\_ text.';
        $this->assertRoundTrip($djot);
    }

    public function testEscapedBrackets(): void
    {
        $djot = 'Use \[square brackets\] literally.';
        $this->assertRoundTrip($djot);
    }

    public function testEscapedBackslash(): void
    {
        $djot = 'A backslash: \\\\ here.';
        $this->assertRoundTrip($djot);
    }

    public function testMixedEscapedCharacters(): void
    {
        $djot = 'Mix of \* and \_ and \[ escapes.';
        $this->assertRoundTrip($djot);
    }

    public function testEscapedAtBoundaries(): void
    {
        $djot = '\*starts and ends\*';
        $this->assertRoundTrip($djot);
    }

    public function testConsecutiveEscapedCharacters(): void
    {
        $djot = 'Multiple \*\*\* consecutive escapes.';
        $this->assertRoundTrip($djot);
    }

    public function testAbbreviationWithSpecialChars(): void
    {
        $djot = <<<'DJOT'
*[C++]: C Plus Plus

The C++ language is powerful.
DJOT;
        $this->assertRoundTrip($djot);
    }

    public function testAbbreviationWithDots(): void
    {
        $djot = <<<'DJOT'
*[e.g.]: for example

Use it e.g. in sentences.
DJOT;
        $this->assertRoundTrip($djot);
    }
}
