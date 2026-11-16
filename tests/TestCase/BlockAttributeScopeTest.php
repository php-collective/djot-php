<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block attribute line left at the end of a container has no block to attach
 * to and is dropped: it never reaches the block or item after the container
 * (jgm/djot.js#175).
 */
class BlockAttributeScopeTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testAttributeStaysInContainer(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
        $this->assertSame($expected, (new DjotConverter(warnings: true))->convert($djot));
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
            'empty item before the next item' => [
                "- {.a}\n- b\n",
                "<ul>\n<li>\n</li>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'empty ordered item before the next item' => [
                "1. {.a}\n2. b\n",
                "<ol>\n<li>\n</li>\n<li>\nb\n</li>\n</ol>\n",
            ],
            'end of an item before the next item' => [
                "- x\n\n  {.a}\n- b\n",
                "<ul>\n<li>\n<p>x</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'end of a quote before a list' => [
                "> {.a}\n- b\n",
                "<blockquote>\n</blockquote>\n<ul>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'end of a quote before a paragraph' => [
                "> {.a}\n\ntail\n",
                "<blockquote>\n</blockquote>\n<p>tail</p>\n",
            ],
            'end of a nested item before the outer item' => [
                "- a\n\n  - {.a}\n- b\n",
                "<ul>\n<li>\na\n<ul>\n<li>\n</li>\n</ul>\n</li>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'item attributes on the separate line still apply' => [
                "- x\n  {.c}\n- b\n",
                "<ul>\n<li class=\"c\">\nx\n</li>\n<li>\nb\n</li>\n</ul>\n",
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
