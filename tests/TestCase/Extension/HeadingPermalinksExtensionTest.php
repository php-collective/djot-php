<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\HeadingPermalinksExtension;
use PHPUnit\Framework\TestCase;

class HeadingPermalinksExtensionTest extends TestCase
{
    public function testAddsPermalink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension());

        $html = $converter->convert('# Hello World');

        $this->assertStringContainsString('class="permalink"', $html);
        $this->assertStringContainsString('¶', $html);
        $this->assertStringContainsString('aria-label="Permalink"', $html);
    }

    public function testCustomSymbol(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            symbol: '#',
        ));

        $html = $converter->convert('# Hello World');

        $this->assertStringContainsString('>#<', $html);
        $this->assertStringNotContainsString('¶', $html);
    }

    public function testPositionBefore(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            position: 'before',
        ));

        $html = $converter->convert('# Hello World');

        // The permalink wrapper should appear before "Hello"
        $this->assertMatchesRegularExpression('/permalink.*Hello/', $html);
    }

    public function testPositionAfter(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            position: 'after',
        ));

        $html = $converter->convert('# Hello World');

        // The text should appear before permalink
        $this->assertMatchesRegularExpression('/Hello.*permalink/', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            cssClass: 'heading-anchor',
        ));

        $html = $converter->convert('# Hello World');

        $this->assertStringContainsString('class="heading-anchor"', $html);
        $this->assertStringNotContainsString('class="permalink"', $html);
    }

    public function testCustomAriaLabel(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            ariaLabel: 'Link to section',
        ));

        $html = $converter->convert('# Hello World');

        $this->assertStringContainsString('aria-label="Link to section"', $html);
    }

    public function testLimitToSpecificLevels(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension(
            levels: [1, 2],
        ));

        $html = $converter->convert("# Heading 1\n\n## Heading 2\n\n### Heading 3");

        // Count permalink occurrences
        $count = substr_count($html, 'class="permalink"');
        $this->assertSame(2, $count, 'Only h1 and h2 should have permalinks');
    }

    public function testMultipleHeadings(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension());

        $html = $converter->convert("# First\n\n## Second\n\n## Third");

        $count = substr_count($html, 'class="permalink"');
        $this->assertSame(3, $count);
    }

    public function testHeadingWithExplicitId(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingPermalinksExtension());

        // In djot, block attributes go on the line before the block
        $html = $converter->convert("{#custom-id}\n# Hello World");

        $this->assertStringContainsString('href="#custom-id"', $html);
    }
}
