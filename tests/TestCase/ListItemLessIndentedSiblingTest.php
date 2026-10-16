<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list item marker indented less than the list continues the list (matches djot.js).
 */
class ListItemLessIndentedSiblingTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testSibling(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'loose, after a blank line' => [
                "  - a\n\n- b",
                "<ul>\n<li>\n<p>a</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'tight' => [
                "  - a\n- b",
                "<ul>\n<li>\na\n</li>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'ordered' => [
                "  1. a\n2. b",
                "<ol>\n<li>\na\n</li>\n<li>\nb\n</li>\n</ol>\n",
            ],
            'nested list in an item' => [
                "- x\n\n    - a\n\n  - b",
                "<ul>\n<li>\nx\n<ul>\n<li>\n<p>a</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'another bullet starts a new list' => [
                "  - a\n+ b",
                "<ul>\n<li>\na\n</li>\n</ul>\n<ul>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'a paragraph still ends the list' => [
                "  - a\n\nb",
                "<ul>\n<li>\na\n</li>\n</ul>\n<p>b</p>\n",
            ],
        ];
    }
}
