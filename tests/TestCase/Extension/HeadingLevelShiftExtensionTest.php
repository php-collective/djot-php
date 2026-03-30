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

        // Headings are shifted, section wrapping preserved
        $this->assertStringContainsString('<h2>Heading 1</h2>', $result);
        $this->assertStringContainsString('<h3>Heading 2</h3>', $result);
        $this->assertStringContainsString('<h4>Heading 3</h4>', $result);
        $this->assertStringContainsString('<section id="Heading-1">', $result);
    }

    public function testShiftByTwo(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("# Heading 1\n\n## Heading 2");

        $this->assertStringContainsString('<h3>Heading 1</h3>', $result);
        $this->assertStringContainsString('<h4>Heading 2</h4>', $result);
    }

    public function testCapsAtH6(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));

        $result = $converter->convert("##### Heading 5\n\n###### Heading 6");

        // h5 + 2 = h6 (capped), h6 + 2 = h6 (capped)
        $this->assertStringContainsString('<h6>Heading 5</h6>', $result);
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

        $this->assertStringContainsString('<h2 class="custom-class">Heading</h2>', $result);
        $this->assertStringContainsString('<section id="Heading">', $result);
    }

    public function testPreservesExplicitId(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        // In djot, attributes go on line before the heading
        $result = $converter->convert("{#my-id}\n# Heading");

        $this->assertStringContainsString('<h2>Heading</h2>', $result);
        $this->assertStringContainsString('<section id="my-id">', $result);
    }

    public function testShiftClampedToValidRange(): void
    {
        // Shift > 5 should be clamped to 5
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 10));

        $result = $converter->convert('# Heading 1');

        // h1 + 5 = h6
        $this->assertStringContainsString('<h6>Heading 1</h6>', $result);
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

        $result = $converter->convert("# Heading 1\n\nContent");

        // Section wrapping is preserved with shifted heading
        $this->assertStringContainsString('<section id="Heading-1">', $result);
        $this->assertStringContainsString('<h2>Heading 1</h2>', $result);
        $this->assertStringContainsString('<p>Content</p>', $result);
    }

    public function testWorksWithMarkdownRenderer(): void
    {
        $converter = DjotConverter::markdown();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\n## Heading 2");

        // Markdown output with shifted levels
        $this->assertStringContainsString('## Heading 1', $result);
        $this->assertStringContainsString('### Heading 2', $result);
    }

    public function testWorksWithPlainTextRenderer(): void
    {
        $converter = DjotConverter::plainText();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $result = $converter->convert("# Heading 1\n\nSome text.");

        // Plain text just renders content
        $this->assertStringContainsString('Heading 1', $result);
        $this->assertStringContainsString('Some text.', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    public function testRepeatedRenderDoesNotMutateOriginalDocument(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));

        $document = $converter->parse('# Heading 1');

        $first = $converter->render($document);
        $second = $converter->render($document);

        $this->assertStringContainsString('<h2>Heading 1</h2>', $first);
        $this->assertStringContainsString('<h2>Heading 1</h2>', $second);
        $this->assertStringNotContainsString('<h3>Heading 1</h3>', $second);
    }
}
