<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unindented line after a list item's paragraph continues it only when it
 * opens no block; a line that does ends the list (matches djot.js).
 */
class ListItemEndsOnBlockStartTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testUnindentedBlockStartEndsItem(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'heading' => [
                "- para\n# H",
                "<ul>\n<li>\npara\n</li>\n</ul>\n<section id=\"H\">\n<h1>H</h1>\n</section>\n",
            ],
            'thematic break' => [
                "1. para\n* * *",
                "<ol>\n<li>\npara\n</li>\n</ol>\n<hr>\n",
            ],
            'block quote' => [
                "- li\n> tail",
                "<ul>\n<li>\nli\n</li>\n</ul>\n<blockquote>\n<p>tail</p>\n</blockquote>\n",
            ],
            'table' => [
                "- para\n| a |",
                "<ul>\n<li>\npara\n</li>\n</ul>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n",
            ],
            'div' => [
                "- li\n:::\nx\n:::",
                "<ul>\n<li>\nli\n</li>\n</ul>\n<div>\n<p>x</p>\n</div>\n",
            ],
            'plain text stays lazy' => [
                "- para\nlazy",
                "<ul>\n<li>\npara\nlazy\n</li>\n</ul>\n",
            ],
            'indented marker' => [
                " - li\n> tail",
                "<ul>\n<li>\nli\n</li>\n</ul>\n<blockquote>\n<p>tail</p>\n</blockquote>\n",
            ],
        ];
    }
}
