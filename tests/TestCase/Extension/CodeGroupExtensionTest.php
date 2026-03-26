<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\CodeGroupExtension;
use PHPUnit\Framework\TestCase;

class CodeGroupExtensionTest extends TestCase
{
    public function testBasicCodeGroup(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` php
echo "Hello";
```

``` javascript
console.log("Hello");
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="code-group"', $html);
        $this->assertStringContainsString('class="code-group-radio"', $html);
        $this->assertStringContainsString('class="code-group-label"', $html);
        $this->assertStringContainsString('class="code-group-panel"', $html);
        $this->assertStringContainsString('>php</label>', $html);
        $this->assertStringContainsString('>javascript</label>', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('language-javascript', $html);
    }

    public function testCustomLabels(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` php [Installation]
composer require example/pkg
```

``` bash [NPM Alternative]
npm install example-pkg
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Installation</label>', $html);
        $this->assertStringContainsString('>NPM Alternative</label>', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('language-bash', $html);
    }

    public function testFirstTabSelectedByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` php
first
```

``` javascript
second
```
:::
DJOT;

        $html = $converter->convert($djot);

        // First radio should be checked
        $this->assertMatchesRegularExpression(
            '/id="codegroup-1-tab-1"[^>]*checked/',
            $html,
        );

        // Second should not be checked
        $this->assertStringNotContainsString('id="codegroup-1-tab-2" checked', $html);
        $this->assertStringNotContainsString('id="codegroup-1-tab-2" class="code-group-radio" checked', $html);
    }

    public function testLabelOnlyNoLanguage(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` [Custom Label]
plain text
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Custom Label</label>', $html);
        // Should not have language class when no language specified
        $this->assertStringContainsString('<code>', $html);
        $this->assertStringNotContainsString('language-', $html);
    }

    public function testFallbackToCodeN(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
```
first block
```

```
second block
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Code 1</label>', $html);
        $this->assertStringContainsString('>Code 2</label>', $html);
    }

    public function testNonCodeGroupDivUnchanged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: custom
``` php
code here
```
:::
DJOT;

        $html = $converter->convert($djot);

        // Should be a normal div, not a code-group
        $this->assertStringContainsString('<div class="custom">', $html);
        $this->assertStringNotContainsString('code-group', $html);
        $this->assertStringNotContainsString('type="radio"', $html);
    }

    public function testEmptyCodeGroupIgnored(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
Just some text, no code blocks.
:::
DJOT;

        $html = $converter->convert($djot);

        // Should render as normal div since no code blocks found
        $this->assertStringNotContainsString('type="radio"', $html);
    }

    public function testCustomClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension(
            wrapperClass: 'vp-code-group',
            panelClass: 'vp-panel',
            labelClass: 'vp-tab',
            radioClass: 'vp-radio',
        ));

        $djot = <<<'DJOT'
::: code-group
``` php
code
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="vp-code-group"', $html);
        $this->assertStringContainsString('class="vp-panel"', $html);
        $this->assertStringContainsString('class="vp-tab"', $html);
        $this->assertStringContainsString('class="vp-radio"', $html);
    }

    public function testCustomIdPrefix(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension(
            idPrefix: 'myprefix',
        ));

        $djot = <<<'DJOT'
::: code-group
``` php
code
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('name="myprefix-1"', $html);
        $this->assertStringContainsString('id="myprefix-1-tab-1"', $html);
    }

    public function testCustomHighlighter(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension(
            highlighter: fn(string $code, ?string $lang) =>
                '<div class="highlighted" data-lang="' . ($lang ?? 'none') . '">' . htmlspecialchars($code) . '</div>',
        ));

        $djot = <<<'DJOT'
::: code-group
``` php
$test = true;
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<div class="highlighted" data-lang="php">', $html);
        $this->assertStringContainsString('$test = true;', $html);
    }

    public function testMultipleCodeGroups(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` php
first group
```
:::

::: code-group
``` javascript
second group
```
:::
DJOT;

        $html = $converter->convert($djot);

        // Each group should have unique IDs
        $this->assertStringContainsString('name="codegroup-1"', $html);
        $this->assertStringContainsString('name="codegroup-2"', $html);
        $this->assertStringContainsString('id="codegroup-1-tab-1"', $html);
        $this->assertStringContainsString('id="codegroup-2-tab-1"', $html);
    }

    public function testPreservesIdAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
{#my-id}
::: code-group
``` php
code
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="code-group"', $html);
        $this->assertStringContainsString('id="my-id"', $html);
    }

    public function testPreservesDataAttributes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
{data-example="test"}
::: code-group
``` php
code
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('data-example="test"', $html);
    }

    public function testCodeContentEscaped(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` html
<div class="test">&amp;</div>
```
:::
DJOT;

        $html = $converter->convert($djot);

        // HTML should be escaped
        $this->assertStringContainsString('&lt;div class=&quot;test&quot;&gt;', $html);
        $this->assertStringContainsString('&amp;amp;', $html);
    }

    public function testLanguageWithSpecialChars(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` c++
int main() {}
```

``` c#
class Main {}
```
:::
DJOT;

        $html = $converter->convert($djot);

        // Labels should show the language
        $this->assertStringContainsString('>c++</label>', $html);
        // Note: c# might be parsed differently, but the extension should handle it
    }

    public function testMixedContentIgnoresNonCodeBlocks(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
Some text here.

``` php
code one
```

More text.

``` javascript
code two
```
:::
DJOT;

        $html = $converter->convert($djot);

        // Should only create tabs for code blocks
        $this->assertStringContainsString('>php</label>', $html);
        $this->assertStringContainsString('>javascript</label>', $html);
        // Should have exactly 2 tabs
        $this->assertEquals(2, substr_count($html, 'class="code-group-label"'));
    }

    public function testLabelWithSpecialChars(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $djot = <<<'DJOT'
::: code-group
``` php [Config & Setup]
$config = [];
```
:::
DJOT;

        $html = $converter->convert($djot);

        // Special chars in label should be escaped
        $this->assertStringContainsString('>Config &amp; Setup</label>', $html);
    }
}
