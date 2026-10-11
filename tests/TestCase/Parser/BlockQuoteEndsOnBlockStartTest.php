<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line without the ">" prefix continues a quoted paragraph only when it opens
 * no block of its own. A line that does ends the quote and starts that block
 * after it (matches djot.js).
 */
class BlockQuoteEndsOnBlockStartTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    #[DataProvider('blockStartProvider')]
    public function testUnquotedBlockStartEndsQuote(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockStartProvider(): array
    {
        return [
            'list item' => [
                "> a\n- tail",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'ordered list item' => [
                "> a\n1. tail",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<ol>\n<li>\ntail\n</li>\n</ol>\n",
            ],
            'heading' => [
                "> a\n# h",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<section id=\"h\">\n<h1>h</h1>\n</section>\n",
            ],
            'code fence' => [
                "> a\n```\ncode\n```",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<pre><code>code\n</code></pre>\n",
            ],
            'div' => [
                "> a\n::: d\ny\n:::",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<div class=\"d\">\n<p>y</p>\n</div>\n",
            ],
            'thematic break' => [
                "> a\n---",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<hr>\n",
            ],
            'table row' => [
                "> a\n| t |",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<table>\n<tr>\n<td>t</td>\n</tr>\n</table>\n",
            ],
            'reference definition' => [
                "> a\n[r]: u",
                "<blockquote>\n<p>a</p>\n</blockquote>\n",
            ],
            'indented list item' => [
                "> a\n  - tail",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'sibling-looking item after a quoted list' => [
                "> - a\n> - b\n- c",
                "<blockquote>\n<ul>\n<li>\na\n</li>\n<li>\nb\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\nc\n</li>\n</ul>\n",
            ],
            'item after a quoted heading' => [
                "> # h\n- tail",
                "<blockquote>\n<h1 id=\"h\">h</h1>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'item after a paragraph in a div' => [
                "> ::: d\n> a\n- tail",
                "<blockquote>\n<div class=\"d\">\n<p>a</p>\n</div>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'number followed by a period' => [
                "> a\n1985. year",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<ol start=\"1985\">\n<li>\nyear\n</li>\n</ol>\n",
            ],
        ];
    }

    #[DataProvider('paragraphTextProvider')]
    public function testUnquotedTextStillContinuesParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function paragraphTextProvider(): array
    {
        return [
            'plain text' => [
                "> a\ntail",
                "<blockquote>\n<p>a\ntail</p>\n</blockquote>\n",
            ],
            'quote marker without a space' => [
                "> a\n>there",
                "<blockquote>\n<p>a\n&gt;there</p>\n</blockquote>\n",
            ],
            'pipe that is no table row' => [
                "> a\n|pipe",
                "<blockquote>\n<p>a\n|pipe</p>\n</blockquote>\n",
            ],
            'reference-like text with two words' => [
                "> a\n[r]: two words",
                "<blockquote>\n<p>a\n[r]: two words</p>\n</blockquote>\n",
            ],
            'indented comment marker' => [
                "> a\n  %%%",
                "<blockquote>\n<p>a\n%%%</p>\n</blockquote>\n",
            ],
            'text after a quoted list item' => [
                "> - a\ntail",
                "<blockquote>\n<ul>\n<li>\na\ntail\n</li>\n</ul>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('indentedPrefixProvider')]
    public function testIndentedPrefixContinuesQuote(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indentedPrefixProvider(): array
    {
        return [
            'indented prefix' => [
                "> a\n  > b",
                "<blockquote>\n<p>a\nb</p>\n</blockquote>\n",
            ],
            'indented bare prefix' => [
                "> a\n  >\n  > b",
                "<blockquote>\n<p>a</p>\n<p>b</p>\n</blockquote>\n",
            ],
        ];
    }
}
