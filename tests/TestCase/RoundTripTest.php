<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
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
}
