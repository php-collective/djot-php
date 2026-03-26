<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\AdmonitionExtension;
use PHPUnit\Framework\TestCase;

class AdmonitionExtensionTest extends TestCase
{
    public function testBasicNote(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: note\nThis is a note.\n:::");

        $this->assertStringContainsString('class="admonition note"', $html);
        $this->assertStringContainsString('role="note"', $html);
        $this->assertStringContainsString('<p class="admonition-title">Note</p>', $html);
        $this->assertStringContainsString('<p>This is a note.</p>', $html);
    }

    public function testWarningWithAlertRole(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: warning\nBe careful!\n:::");

        $this->assertStringContainsString('class="admonition warning"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('<p class="admonition-title">Warning</p>', $html);
    }

    public function testDangerWithAlertRole(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: danger\nCritical issue!\n:::");

        $this->assertStringContainsString('class="admonition danger"', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function testTipWithNoteRole(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: tip\nHelpful tip.\n:::");

        $this->assertStringContainsString('class="admonition tip"', $html);
        $this->assertStringContainsString('role="note"', $html);
    }

    public function testCustomTitle(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("{title=\"Watch Out!\"}\n::: warning\nContent here.\n:::");

        $this->assertStringContainsString('<p class="admonition-title">Watch Out!</p>', $html);
        $this->assertStringNotContainsString('>Warning<', $html);
    }

    public function testCollapsible(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("{collapsible}\n::: tip\nHidden content.\n:::");

        $this->assertStringContainsString('<details class="admonition tip">', $html);
        $this->assertStringContainsString('<summary>Tip</summary>', $html);
        $this->assertStringContainsString('</details>', $html);
        $this->assertStringNotContainsString('role=', $html);
        $this->assertStringNotContainsString(' open', $html);
    }

    public function testCollapsibleOpen(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("{collapsible=open}\n::: danger\nExpanded by default.\n:::");

        $this->assertStringContainsString('<details class="admonition danger" open>', $html);
        $this->assertStringContainsString('<summary>Danger</summary>', $html);
    }

    public function testCollapsibleWithCustomTitle(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("{collapsible title=\"Click Me\"}\n::: info\nContent.\n:::");

        $this->assertStringContainsString('<details class="admonition info">', $html);
        $this->assertStringContainsString('<summary>Click Me</summary>', $html);
    }

    public function testNonAdmonitionDivUnchanged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: custom\nContent.\n:::");

        $this->assertStringContainsString('<div class="custom">', $html);
        $this->assertStringNotContainsString('admonition', $html);
        $this->assertStringNotContainsString('role=', $html);
    }

    public function testAllDefaultTypes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $types = ['note', 'tip', 'warning', 'danger', 'info', 'success'];

        foreach ($types as $type) {
            $html = $converter->convert("::: $type\nContent.\n:::");
            $this->assertStringContainsString("class=\"admonition $type\"", $html, "Type '$type' should be recognized");
        }
    }

    public function testCustomTypes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            types: ['custom', 'special'],
        ));

        $html = $converter->convert("::: custom\nContent.\n:::");
        $this->assertStringContainsString('class="admonition custom"', $html);

        $html = $converter->convert("::: note\nContent.\n:::");
        $this->assertStringNotContainsString('admonition', $html);
    }

    public function testDisableDefaultTitle(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            defaultTitle: false,
        ));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('class="admonition note"', $html);
        $this->assertStringNotContainsString('admonition-title', $html);
        $this->assertStringNotContainsString('>Note<', $html);
    }

    public function testCustomTitleTag(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            titleTag: 'strong',
        ));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('<strong class="admonition-title">Note</strong>', $html);
    }

    public function testCustomTitleClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            titleClass: 'custom-title',
        ));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('class="custom-title"', $html);
    }

    public function testCustomContainerClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            containerClass: 'callout',
        ));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('class="callout note"', $html);
    }

    public function testAdditionalAttributesPreserveTypeClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        // Using id and data attributes (not class) preserves the type class from the fence opener
        $html = $converter->convert("{#my-admonition data-extra=\"value\"}\n::: note\nContent.\n:::");

        $this->assertStringContainsString('class="admonition note"', $html);
        $this->assertStringContainsString('id="my-admonition"', $html);
        $this->assertStringContainsString('data-extra="value"', $html);
    }

    public function testNestedContent(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $djot = <<<'DJOT'
::: warning
Here is a list:

- Item 1
- Item 2

And some code:

``` php
echo "hello";
```
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="admonition warning"', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('echo "hello"', $html);
    }

    public function testMultipleAdmonitions(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $djot = <<<'DJOT'
::: note
First note.
:::

::: warning
A warning.
:::
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="admonition note"', $html);
        $this->assertStringContainsString('class="admonition warning"', $html);
        $this->assertStringContainsString('<p class="admonition-title">Note</p>', $html);
        $this->assertStringContainsString('<p class="admonition-title">Warning</p>', $html);
    }

    public function testHtmlEscaping(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("{title=\"<script>alert('xss')</script>\"}\n::: note\nContent.\n:::");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testInfoAndSuccessHaveNoteRole(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: info\nInformation.\n:::");
        $this->assertStringContainsString('role="note"', $html);

        $html = $converter->convert("::: success\nSuccess!\n:::");
        $this->assertStringContainsString('role="note"', $html);
    }

    public function testIconsDisabledByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension());

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringNotContainsString('admonition-icon', $html);
        $this->assertStringContainsString('<p class="admonition-title">Note</p>', $html);
    }

    public function testIconsEnabledWithTrue(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(icons: true));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('<span class="admonition-icon">📝</span> Note', $html);
    }

    public function testDefaultIconsForAllTypes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(icons: true));

        $expectations = [
            'note' => '📝',
            'tip' => '💡',
            'warning' => '⚠️',
            'danger' => '🚨',
            'info' => 'ℹ️',
            'success' => '✅',
        ];

        foreach ($expectations as $type => $expectedIcon) {
            $html = $converter->convert("::: $type\nContent.\n:::");
            $this->assertStringContainsString(
                '<span class="admonition-icon">' . $expectedIcon . '</span>',
                $html,
                "Type '$type' should have icon '$expectedIcon'",
            );
        }
    }

    public function testCustomIcons(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            icons: ['note' => '📝', 'tip' => '🌟'],
        ));

        $html = $converter->convert("::: note\nContent.\n:::");
        $this->assertStringContainsString('<span class="admonition-icon">📝</span> Note', $html);

        $html = $converter->convert("::: tip\nContent.\n:::");
        $this->assertStringContainsString('<span class="admonition-icon">🌟</span> Tip', $html);
    }

    public function testCustomIconsMissingType(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            icons: ['note' => '📝'],
        ));

        // Warning has no custom icon, so no icon should be rendered
        $html = $converter->convert("::: warning\nContent.\n:::");

        $this->assertStringNotContainsString('admonition-icon', $html);
        $this->assertStringContainsString('<p class="admonition-title">Warning</p>', $html);
    }

    public function testCustomIconClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(
            icons: true,
            iconClass: 'custom-icon',
        ));

        $html = $converter->convert("::: note\nContent.\n:::");

        $this->assertStringContainsString('<span class="custom-icon">📝</span> Note', $html);
        $this->assertStringNotContainsString('admonition-icon', $html);
    }

    public function testIconsInCollapsible(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(icons: true));

        $html = $converter->convert("{collapsible}\n::: tip\nContent.\n:::");

        $this->assertStringContainsString('<summary><span class="admonition-icon">💡</span> Tip</summary>', $html);
    }

    public function testIconsWithCustomTitle(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AdmonitionExtension(icons: true));

        $html = $converter->convert("{title=\"Custom Title\"}\n::: warning\nContent.\n:::");

        $this->assertStringContainsString('<span class="admonition-icon">⚠️</span> Custom Title', $html);
    }

    public function testIconsPreserveDefaultConstants(): void
    {
        $this->assertSame('📝', AdmonitionExtension::DEFAULT_ICONS['note']);
        $this->assertSame('💡', AdmonitionExtension::DEFAULT_ICONS['tip']);
        $this->assertSame('⚠️', AdmonitionExtension::DEFAULT_ICONS['warning']);
        $this->assertSame('🚨', AdmonitionExtension::DEFAULT_ICONS['danger']);
        $this->assertSame('ℹ️', AdmonitionExtension::DEFAULT_ICONS['info']);
        $this->assertSame('✅', AdmonitionExtension::DEFAULT_ICONS['success']);
    }
}
