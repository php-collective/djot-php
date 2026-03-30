<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Transform;

use Djot\DjotConverter;
use Djot\Transform\HeadingLevelShiftTransform;
use PHPUnit\Framework\TestCase;

class HeadingLevelShiftTransformTest extends TestCase
{
    public function testTransformReturnsShiftedCopyWithoutMutatingOriginalDocument(): void
    {
        $converter = new DjotConverter();
        $document = $converter->parse('# Heading 1');

        $transformed = $converter->transform($document, new HeadingLevelShiftTransform(1));

        $this->assertStringContainsString('<h1>Heading 1</h1>', $converter->render($document));
        $this->assertStringContainsString('<h2>Heading 1</h2>', $converter->render($transformed));
    }

    public function testTransformedDocumentCanBeRenderedRepeatedly(): void
    {
        $converter = new DjotConverter();
        $document = $converter->parse('# Heading 1');
        $transformed = $converter->transform($document, new HeadingLevelShiftTransform(1));

        $first = $converter->render($transformed);
        $second = $converter->render($transformed);

        $this->assertStringContainsString('<h2>Heading 1</h2>', $first);
        $this->assertStringContainsString('<h2>Heading 1</h2>', $second);
        $this->assertStringNotContainsString('<h3>Heading 1</h3>', $second);
    }
}
