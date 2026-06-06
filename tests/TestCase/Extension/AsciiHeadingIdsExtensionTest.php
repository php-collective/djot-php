<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\AsciiHeadingIdsExtension;
use PHPUnit\Framework\TestCase;

class AsciiHeadingIdsExtensionTest extends TestCase
{
    public function testDefaultPreservesNonAscii(): void
    {
        $html = (new DjotConverter())->convert("# über café\n");

        $this->assertStringContainsString('<section id="über-café">', $html);
    }

    public function testExtensionFoldsHeadingIdToAscii(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert("# über café\n");

        $this->assertStringContainsString('<section id="uber-cafe">', $html);
    }

    public function testExtensionKeepsImplicitReferenceInParity(): void
    {
        // The folded id must also be used by the `[Heading][]` link target, so the
        // anchor still resolves (parser/renderer parity).
        $converter = new DjotConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert("# über café\n\nsee [über café][]\n");

        $this->assertStringContainsString('<section id="uber-cafe">', $html);
        $this->assertStringContainsString('href="#uber-cafe"', $html);
    }
}
