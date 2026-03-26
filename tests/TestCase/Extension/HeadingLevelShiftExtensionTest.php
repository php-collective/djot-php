<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\HeadingLevelShiftExtension;
use PHPUnit\Framework\TestCase;

class HeadingLevelShiftExtensionTest extends TestCase
{
    public function testShiftByOne(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\n## Heading 2\n\n### Heading 3");

        $this->assertStringContainsString('<h2 id="Heading-1">Heading 1</h2>', $result);
        $this->assertStringContainsString('<h3 id="Heading-2">Heading 2</h3>', $result);
        $this->assertStringContainsString('<h4 id="Heading-3">Heading 3</h4>', $result);
    }

    public function testShiftByTwo(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("# Heading 1\n\n## Heading 2");

        $this->assertStringContainsString('<h3 id="Heading-1">Heading 1</h3>', $result);
        $this->assertStringContainsString('<h4 id="Heading-2">Heading 2</h4>', $result);
    }

    public function testCapsAtH6(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("##### Heading 5\n\n###### Heading 6");

        // h5 + 2 = h6 (capped), h6 + 2 = h6 (capped, no change)
        $this->assertStringContainsString('<h6 id="Heading-5">Heading 5</h6>', $result);
        // h6 stays h6, so extension doesn't intercept - section wrapping applies
        $this->assertStringContainsString('<h6>Heading 6</h6>', $result);
    }

    public function testZeroShiftDoesNothing(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 0));

        $result = $converter->convert('# Heading 1');

        // Zero shift - default section-wrapped rendering
        $this->assertStringContainsString('<section id="Heading-1">', $result);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $result);
    }

    public function testPreservesAttributes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // In djot, attributes go on line before the heading
        $result = $converter->convert("{.custom-class}\n# Heading");

        $this->assertStringContainsString('<h2 id="Heading" class="custom-class">Heading</h2>', $result);
    }

    public function testPreservesExplicitId(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // In djot, attributes go on line before the heading
        $result = $converter->convert("{#my-id}\n# Heading");

        $this->assertStringContainsString('<h2 id="my-id">Heading</h2>', $result);
    }

    public function testShiftClampedToValidRange(): void
    {
        // Shift > 5 should be clamped to 5
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 10));

        $result = $converter->convert('# Heading 1');

        // h1 + 5 = h6
        $this->assertStringContainsString('<h6 id="Heading-1">Heading 1</h6>', $result);
    }

    public function testNegativeShiftClampedToZero(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: -1));

        $result = $converter->convert('# Heading 1');

        // Negative shift clamped to 0 - default section-wrapped rendering
        $this->assertStringContainsString('<section id="Heading-1">', $result);
        $this->assertStringContainsString('<h1>Heading 1</h1>', $result);
    }

    public function testWorksWithSectionWrapping(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // When extension intercepts, it provides its own HTML (no section wrapper)
        // Section wrapping only happens when extension doesn't preventDefault
        $result = $converter->convert("# Heading 1\n\nContent");

        $this->assertStringContainsString('<h2 id="Heading-1">Heading 1</h2>', $result);
        $this->assertStringContainsString('<p>Content</p>', $result);
    }
}
