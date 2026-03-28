<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\TableOfContentsExtension;
use Djot\Extension\TabsExtension;
use PHPUnit\Framework\TestCase;

class TabsExtensionTest extends TestCase
{
    public function testBasicCssModeTabs(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        // Note: Outer div uses 4 colons, inner divs use 3 colons for proper nesting
        $djot = <<<'DJOT'
:::: tabs
::: tab
### First Tab

Content one.
:::

::: tab
### Second Tab

Content two.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="tabs"', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('class="tabs-radio"', $html);
        $this->assertStringContainsString('class="tabs-label"', $html);
        $this->assertStringContainsString('>First Tab</label>', $html);
        $this->assertStringContainsString('>Second Tab</label>', $html);
        $this->assertStringContainsString('class="tabs-panel"', $html);
        $this->assertStringContainsString('Content one.', $html);
        $this->assertStringContainsString('Content two.', $html);
    }

    public function testFirstTabSelectedByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab 1

Content.
:::
::: tab
### Tab 2

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // First radio should be checked
        $this->assertMatchesRegularExpression('/id="tabset-1-tab-1"[^>]*checked/', $html);
        // Second should not be checked
        $this->assertStringNotContainsString('id="tabset-1-tab-2" class="tabs-radio" checked', $html);
    }

    public function testExplicitSelectedTab(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab 1

Content.
:::

{selected}
::: tab
### Tab 2
Selected content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Second tab should be checked, first should not
        $this->assertStringNotContainsString('id="tabset-1-tab-1" class="tabs-radio" checked', $html);
        $this->assertMatchesRegularExpression('/id="tabset-1-tab-2"[^>]*checked/', $html);
    }

    public function testLabelFromAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
{label="Custom Label"}
::: tab
Content without heading.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Custom Label</label>', $html);
        $this->assertStringContainsString('Content without heading.', $html);
    }

    public function testLabelAttributeTakesPrecedence(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
{label="Attribute Label"}
::: tab
### Heading Label

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Attribute Label</label>', $html);
        // Heading should remain in content when label attribute is used
        $this->assertStringContainsString('Heading Label', $html);
    }

    public function testHeadingRemovedFromContent(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab Title

Content here.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('>Tab Title</label>', $html);
        // Heading should not appear twice (once in label, once in content)
        $this->assertEquals(1, substr_count($html, 'Tab Title'));
    }

    public function testAriaMode(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension(mode: 'aria'));

        $djot = <<<'DJOT'
:::: tabs
::: tab
### First
Content 1.
:::
::: tab
### Second
Content 2.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('aria-selected="true"', $html);
        $this->assertStringContainsString('aria-selected="false"', $html);
        $this->assertStringContainsString('aria-controls=', $html);
        $this->assertStringContainsString('aria-labelledby=', $html);
        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('type="radio"', $html);
    }

    public function testAriaHiddenAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension(mode: 'aria'));

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab 1

Content.
:::
::: tab
### Tab 2

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // First panel should not be hidden
        $this->assertMatchesRegularExpression('/id="tabset-1-panel-1"[^>]*>/', $html);
        $this->assertStringNotContainsString('id="tabset-1-panel-1" hidden', $html);
        // Second panel should be hidden
        $this->assertMatchesRegularExpression('/id="tabset-1-panel-2"[^>]*hidden/', $html);
    }

    public function testAriaTabindex(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension(mode: 'aria'));

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab 1

Content.
:::
::: tab
### Tab 2

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Selected tab should not have tabindex="-1"
        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*>Tab 1/', $html);
        // Non-selected tabs should have tabindex="-1"
        $this->assertMatchesRegularExpression('/tabindex="-1"[^>]*>Tab 2/', $html);
    }

    public function testNonTabsDivUnchanged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $html = $converter->convert("::: custom\nContent.\n:::");

        $this->assertStringContainsString('<div class="custom">', $html);
        $this->assertStringNotContainsString('tabs-radio', $html);
        $this->assertStringNotContainsString('tabs-label', $html);
    }

    public function testEmptyTabsWrapper(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $html = $converter->convert("::: tabs\nJust content, no tab divs.\n:::");

        // Should render as normal div when no tab children
        $this->assertStringContainsString('<div class="tabs">', $html);
        $this->assertStringNotContainsString('tabs-radio', $html);
    }

    public function testMultipleTabSets(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Set 1 Tab 1

Content.
:::
::::

:::: tabs
::: tab
### Set 2 Tab 1

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Should have different IDs for each set
        $this->assertStringContainsString('name="tabset-1"', $html);
        $this->assertStringContainsString('name="tabset-2"', $html);
    }

    public function testCustomClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension(
            wrapperClass: 'my-tabs',
            tabClass: 'my-panel',
            labelClass: 'my-label',
            radioClass: 'my-radio',
        ));

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="my-tabs"', $html);
        $this->assertStringContainsString('class="my-panel"', $html);
        $this->assertStringContainsString('class="my-label"', $html);
        $this->assertStringContainsString('class="my-radio"', $html);
    }

    public function testCustomIdPrefix(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension(idPrefix: 'mytabs'));

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Tab

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('name="mytabs-1"', $html);
        $this->assertStringContainsString('id="mytabs-1-tab-1"', $html);
    }

    public function testNestedContent(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Code Example

Here is some code:

``` php
echo "Hello";
```

And a list:

- Item 1
- Item 2
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="tabs"', $html);
        $this->assertStringContainsString('echo "Hello"', $html);
        $this->assertStringContainsString('<li>', $html);
    }

    public function testAdditionalWrapperClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
{.extra-class #my-tabs}
::: tabs
::: tab
### Tab

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('id="my-tabs"', $html);
        // Note: due to parser behavior, extra-class may replace tabs class
        // This test documents current behavior
    }

    public function testCustomTabId(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
::: tabs
{#my-custom-tab}
::: tab
### Tab

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('id="my-custom-tab"', $html);
    }

    public function testHtmlEscaping(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
::: tabs
{label="<script>alert('xss')</script>"}
::: tab
Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testSingleTab(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
### Only Tab

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Single tab should still render as tabs (user's choice)
        $this->assertStringContainsString('class="tabs"', $html);
        $this->assertStringContainsString('type="radio"', $html);
    }

    public function testFallbackLabel(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
:::: tabs
::: tab
Content without heading or label.
:::
::: tab
Another tab without label.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Should generate fallback labels
        $this->assertStringContainsString('>Tab ', $html);
    }

    public function testMixedTabChildren(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
::::: tabs
Some intro text that's not a tab.

::: tab
### Tab 1

Content.
:::

A paragraph between tabs.

:::: tab
### Tab 2

Content.
::::
:::::
DJOT;

        $html = $converter->convert($djot);

        // Should only process tab divs, ignore other content
        $this->assertStringContainsString('>Tab 1</label>', $html);
        $this->assertStringContainsString('>Tab 2</label>', $html);
        // Non-tab content should be ignored
        $this->assertStringNotContainsString('intro text', $html);
    }

    public function testClassMergingWithAttributes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        // When an attribute block specifies a class before a tabs div,
        // both the 'tabs' class and the custom class should be present
        $djot = <<<'DJOT'
{.custom-style #my-tabs}
:::: tabs
::: tab
### Tab 1

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Should have both the tabs class and custom class
        $this->assertStringContainsString('class="tabs custom-style"', $html);
        $this->assertStringContainsString('id="my-tabs"', $html);
    }

    public function testClassMergingMultipleClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
{.extra-class .another-class}
:::: tabs
::: tab
### Tab 1

Content.
:::
::::
DJOT;

        $html = $converter->convert($djot);

        // Should have all classes merged
        $this->assertStringContainsString('class="tabs extra-class another-class"', $html);
    }

    /**
     * Test that TabsExtension works correctly with TableOfContentsExtension.
     *
     * This is a regression test for https://github.com/php-collective/djot-php/issues/139
     * The bug was that TabsExtension called $converter->render() for nested content,
     * which triggered clear() on all extensions including TOC, wiping collected headings.
     */
    public function testCompatibilityWithTableOfContents(): void
    {
        $converter = new DjotConverter();
        $toc = new TableOfContentsExtension(minLevel: 2, maxLevel: 4, position: 'top');
        $converter->addExtension($toc);
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
# Main Title

## Heading Before Tabs

Content before tabs.

## Tabs Section

:::: tabs
::: tab
### Tab One
Content one.
:::
::: tab
### Tab Two
Content two.
:::
::::

## Heading After Tabs

Content after tabs.
DJOT;

        $html = $converter->convert($djot);
        $tocData = $toc->getToc();

        // All H2 headings should be captured in TOC (minLevel=2)
        $this->assertCount(3, $tocData, 'TOC should capture all 3 H2 headings');

        $headingTexts = array_column($tocData, 'text');
        $this->assertContains('Heading Before Tabs', $headingTexts);
        $this->assertContains('Tabs Section', $headingTexts);
        $this->assertContains('Heading After Tabs', $headingTexts);

        // Verify TOC HTML is present and contains all headings
        $this->assertStringContainsString('Heading Before Tabs', $html);
        $this->assertStringContainsString('Tabs Section', $html);
        $this->assertStringContainsString('Heading After Tabs', $html);
    }

    /**
     * Test that headings inside tabs are also captured by TOC.
     */
    public function testTocCapturesHeadingsInsideTabs(): void
    {
        $converter = new DjotConverter();
        $toc = new TableOfContentsExtension(minLevel: 2, maxLevel: 4, position: 'top');
        $converter->addExtension($toc);
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
## Before Tabs

:::: tabs
::: tab
### Tab Heading One
Content.
:::
::: tab
### Tab Heading Two
Content.
:::
::::

## After Tabs
DJOT;

        $converter->convert($djot);
        $tocData = $toc->getToc();

        $headingTexts = array_column($tocData, 'text');

        // Both H2 headings should be captured
        $this->assertContains('Before Tabs', $headingTexts);
        $this->assertContains('After Tabs', $headingTexts);

        // Note: Tab headings (H3) that become labels are intentionally not
        // included in the rendered content, so they won't be in TOC.
        // This is expected behavior - the heading is used as tab label instead.
    }

    /**
     * Test TOC with tabs when using label attributes (headings remain in content).
     */
    public function testTocWithTabsUsingLabelAttribute(): void
    {
        $converter = new DjotConverter();
        $toc = new TableOfContentsExtension(minLevel: 2, maxLevel: 4, position: 'top');
        $converter->addExtension($toc);
        $converter->addExtension(new TabsExtension());

        $djot = <<<'DJOT'
## Main Section

:::: tabs
{label="Custom Label"}
::: tab
### Heading Inside Tab

This heading should be in both content and TOC since we're using label attribute.
:::
::::

## Another Section
DJOT;

        $converter->convert($djot);
        $tocData = $toc->getToc();

        $headingTexts = array_column($tocData, 'text');

        $this->assertContains('Main Section', $headingTexts);
        $this->assertContains('Another Section', $headingTexts);
        // When label attribute is used, the heading inside tab remains in content
        // and should be captured by TOC
        $this->assertContains('Heading Inside Tab', $headingTexts);
    }
}
