<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Renderer content loss: the non-HTML renderers (markdown/plain/ansi) must not
 * silently drop content the HTML renderer keeps.
 *
 * Ported from carve-php commit 217b72f.
 */
class RendererContentLossTest extends TestCase
{
    public function testEscapedTextIsNotDroppedInNonHtmlRenderers(): void
    {
        $md = DjotConverter::markdown()->convert('a \*lit\* b');
        $this->assertStringContainsString('lit', $md);
        $this->assertStringContainsString('*', $md);

        $plain = DjotConverter::plainText()->convert('a \*lit\* b');
        $this->assertStringContainsString('*lit*', $plain);

        $ansi = DjotConverter::ansi()->convert('a \*lit\* b');
        $this->assertStringContainsString('*lit*', $ansi);
    }

    public function testAbbreviationTitlePreservedInMarkdown(): void
    {
        $md = DjotConverter::markdown()->convert("The HTML spec.\n\n*[HTML]: HyperText Markup Language");
        $this->assertStringContainsString('HyperText Markup Language', $md);
    }

    public function testFigureCaptionNotGluedInMarkdown(): void
    {
        $md = DjotConverter::markdown()->convert("![a](i.png)\n^ Cap text");
        // caption sits on its own line, not glued to the image
        $this->assertStringNotContainsString('i.png)Cap', $md);
        $this->assertStringContainsString('Cap text', $md);
    }

    public function testDivTitlePreservedInNonHtmlRenderers(): void
    {
        // A Div carries a `title` attribute via Djot's attribute syntax; the
        // non-HTML renderers must surface it as a leading line, not drop it.
        $src = "{title=\"Heads up\"}\n:::\nbody\n:::";
        $this->assertStringContainsString('Heads up', DjotConverter::markdown()->convert($src));
        $this->assertStringContainsString('Heads up', DjotConverter::plainText()->convert($src));
        $this->assertStringContainsString('Heads up', DjotConverter::ansi()->convert($src));
    }
}
