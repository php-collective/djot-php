<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block attribute line left at the end of a div, or followed by a blank line
 * inside a list item, does not reach the block after the container (matches
 * djot.js).
 */
class BlockAttributeScopeTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testAttributeStaysInContainer(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'end of a div' => [
                "::: d\n{#x}\n:::\ntail",
                "<div class=\"d\">\n</div>\n<p>tail</p>\n",
            ],
            'end of an empty list item' => [
                "- {#x}\n\ntail",
                "<ul>\n<li>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'end of a list item' => [
                "- a\n\n  {#x}\n\ntail",
                "<ul>\n<li>\n<p>a</p>\n</li>\n</ul>\n<p>tail</p>\n",
            ],
            'before a block in the div' => [
                "::: d\n{#x}\npara\n:::",
                "<div class=\"d\">\n<p id=\"x\">para</p>\n</div>\n",
            ],
            'before the next block' => [
                "{#x}\npara",
                "<p id=\"x\">para</p>\n",
            ],
        ];
    }
}
