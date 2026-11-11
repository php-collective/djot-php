<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\RawBlock;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block attributes before reference-like lines, marker-line attributes,
 * empty raw blocks and a backslash before trailing spaces (matches djot.js).
 */
class SmallConformanceLeftoversTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testOutput(string $djot, string $expected): void
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
            'attributes before a reference-like line' => [
                "{.c}\n[r]: p q\n",
                "<p class=\"c\">[r]: p q</p>\n",
            ],
            'boolean attribute before a reference-like line' => [
                "{y}\n[r]: p q\n",
                "<p y=\"\">[r]: p q</p>\n",
            ],
            'attributes before a real reference definition' => [
                "{.c}\n[r]: p\n\n[a][r]\n",
                "<p><a href=\"p\" class=\"c\">a</a></p>\n",
            ],
            'marker-line attributes over an indented line' => [
                "- {#t .p}\n x\n\n- b\n",
                "<ul>\n<li>\n<p id=\"t\" class=\"p\">x</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'marker-line attributes over two content-indented lines' => [
                "1. {.c}\n   x\n   y\n",
                "<ol>\n<li>\n<p class=\"c\">x\ny</p>\n</li>\n</ol>\n",
            ],
            'marker-line braced inline stays inline' => [
                "- {_a_}\n  x\n",
                "<ul>\n<li>\n<em>a</em>\nx\n</li>\n</ul>\n",
            ],
            'empty unclosed raw block' => [
                "```=html\n",
                '',
            ],
            'empty closed raw block in a quote' => [
                "> ```=html\n> ```\n",
                "<blockquote>\n</blockquote>\n",
            ],
            'raw block with one blank line' => [
                "```=html\n\n```\n",
                "\n",
            ],
            'raw block with two blank lines' => [
                "```=html\n\n\n",
                "\n\n",
            ],
            'backslash before a trailing space ends the paragraph with a break' => [
                "x \\ \n",
                "<p>x<br>\n</p>\n",
            ],
            'backslash before a trailing tab without a final newline' => [
                "x \\\t",
                "<p>x<br>\n</p>\n",
            ],
            'backslash before a trailing space in a heading' => [
                "# h \\  \n\npara\n",
                "<section id=\"h\">\n<h1>h<br>\n</h1>\n<p>para</p>\n</section>\n",
            ],
            'backslash before a space at the end of link text' => [
                "[a \\ ](u)\n",
                "<p><a href=\"u\">a &nbsp;</a></p>\n",
            ],
            'backslash before a space at the end of a table cell' => [
                "| a \\ |\n",
                "<table>\n<tr>\n<td>a &nbsp;</td>\n</tr>\n</table>\n",
            ],
        ];
    }

    public function testRawBlockWithoutLinesIsEmpty(): void
    {
        $doc = (new BlockParser())->parse("``` =html\n");
        $block = $doc->getChildren()[0];
        $this->assertInstanceOf(RawBlock::class, $block);
        $this->assertSame('', $block->getContent());

        $doc = (new BlockParser())->parse("``` =html\n\n");
        $block = $doc->getChildren()[0];
        $this->assertInstanceOf(RawBlock::class, $block);
        $this->assertSame("\n", $block->getContent());
    }
}
