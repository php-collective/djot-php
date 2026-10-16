<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block line at or left of the column of a list opened on an item's marker line closes that list's paragraph, so a later unindented line is no lazy continuation (matches djot.js).
 */
class ListItemMarkerLineBlockTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testMarkerLineBlock(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'code block at the sublist column' => [
                "- - para\n  ```\n  x\n  ```\ntail",
                "<ul>\n<li>\n<ul>\n<li>\npara\n</li>\n</ul>\n<pre><code>x\n</code></pre>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'unclosed code block ends with the item' => [
                "- - para\n  ```\n  x\ntail",
                "<ul>\n<li>\n<ul>\n<li>\npara\n</li>\n</ul>\n<pre><code>x\n</code></pre>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'thematic break at the sublist column' => [
                "1. - para\n   * * *\ntail",
                "<ol>\n<li>\n<ul>\n<li>\npara\n</li>\n</ul>\n<hr>\n</li>\n</ol>\n<p>tail</p>\n",
            ],
            'table left of the sublist column' => [
                "- 1. para\n | a |\ntail",
                "<ul>\n<li>\n<ol>\n<li>\npara\n</li>\n</ol>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'reference definition left of the sublist column' => [
                "[r][]\n\n- 1. para\n [r]: /u\ntail",
                "<p><a href=\"/u\">r</a></p>\n<ul>\n<li>\n<ol>\n<li>\npara\n</li>\n</ol>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'text still continues the paragraph' => [
                "- - a\n  b",
                "<ul>\n<li>\n<ul>\n<li>\na\nb\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'heading after a definition' => [
                "- : t\n  # H",
                "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n</dd>\n</dl>\n<h1 id=\"H\">H</h1>\n</li>\n</ul>\n",
            ],
        ];
    }
}
