<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\TabNormalizationExtension;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TabNormalizationExtensionTest extends TestCase
{
    public function testTabsPreservedWithoutExtension(): void
    {
        $html = (new DjotConverter())->convert("```\n\tcode\n```");
        $this->assertStringContainsString("<pre><code>\tcode\n", $html);
    }

    public function testConvertsCodeBlockTabsToFourSpacesByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabNormalizationExtension());

        $html = $converter->convert("```\n\tcode\n```");
        $this->assertStringContainsString("<pre><code>    code\n", $html);
        $this->assertStringNotContainsString("\t", $html);
    }

    public function testConvertsInlineCodeTabs(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabNormalizationExtension());

        $html = $converter->convert("`a\tb`");
        $this->assertStringContainsString('<code>a    b</code>', $html);
    }

    public function testCustomWidth(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TabNormalizationExtension(2));

        $html = $converter->convert("```\n\tcode\n```");
        $this->assertStringContainsString("<pre><code>  code\n", $html);
    }

    public function testRejectsWidthBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TabNormalizationExtension(0);
    }
}
