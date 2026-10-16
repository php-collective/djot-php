<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A continuation line of a list item keeps its indentation relative to the
 * item's content, so a container nested in the item still sees whether the
 * line reaches its own content column (matches djot.js).
 */
class ListItemNestedIndentTest extends TestCase
{
    #[DataProvider('indentProvider')]
    public function testNestedIndent(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indentProvider(): array
    {
        return [
            'marker past the inner content column' => [
                "- - a\n    - b",
                "<ul>\n<li>\n<ul>\n<li>\na\n- b\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'marker inside the inner content column' => [
                "- - a\n   - b",
                "<ul>\n<li>\n<ul>\n<li>\na\n- b\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'heading line in a note inside an item' => [
                "- [^n]: para\n   # H\n\n[^n]",
                "<ul>\n<li>\n</li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para\n# H<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }
}
