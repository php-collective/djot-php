<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Leading spaces before a block opener do not turn it into paragraph text
 * (matches djot.js).
 */
class IndentedBlockStartTest extends TestCase
{
    #[DataProvider('indentedProvider')]
    public function testIndentedBlockStart(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indentedProvider(): array
    {
        return [
            'block quote' => [
                " > a\n > b",
                "<blockquote>\n<p>a\nb</p>\n</blockquote>\n",
            ],
            'block quote with a lazy line' => [
                "  > a\nb",
                "<blockquote>\n<p>a\nb</p>\n</blockquote>\n",
            ],
            'heading past three spaces' => [
                '    # H',
                "<section id=\"H\">\n<h1>H</h1>\n</section>\n",
            ],
            'table' => [
                " | a |\n | b |",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n<tr>\n<td>b</td>\n</tr>\n</table>\n",
            ],
            'definition list' => [
                " : t\n\n   d",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n</dd>\n</dl>\n",
            ],
            'block attributes' => [
                " {#x}\npara",
                "<p id=\"x\">para</p>\n",
            ],
            'block attributes before a quote' => [
                " {.c}\n> q",
                "<blockquote class=\"c\">\n<p>q</p>\n</blockquote>\n",
            ],
            'caption' => [
                "| a |\n ^ cap",
                "<table>\n<caption>cap</caption>\n<tr>\n<td>a</td>\n</tr>\n</table>\n",
            ],
            'heading inside a quote' => [
                '>     # H',
                "<blockquote>\n<h1 id=\"H\">H</h1>\n</blockquote>\n",
            ],
            'lone attribute line' => [
                ' {#x}',
                '',
            ],
            'attribute line inside a quote' => [
                '>  {#x}',
                "<blockquote>\n</blockquote>\n",
            ],
            'attribute line ending a footnote' => [
                "text [^n]\n\n[^n]: > para\n      {#x}",
                "<p>text <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<blockquote>\n<p>para</p>\n</blockquote>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }
}
