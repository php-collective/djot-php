<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A footnote defined in a list item takes unindented paragraph lines like the
 * item's own paragraph would, and a block line too shallow for the note ends
 * it (matches djot.js).
 */
class ListItemFootnoteLazyTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testFootnoteInItem(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'bullet item' => [
                "- [^p]: n\nlazy\n\n[^p]",
                "<ul>\n<li>\n</li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n\nlazy<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'ordered item' => [
                "1. [^p]: n\nlazy\n\n[^p]",
                "<ol>\n<li>\n</li>\n</ol>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n\nlazy<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'table line shallower than the note' => [
                "1. [^n]: para\n | a |\ntail\n\n[^n]",
                "<ol>\n<li>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</li>\n</ol>\n<p>tail</p>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'block line at the item content column' => [
                "1. [^n]: para\n   * * *\ntail\n\n[^n]",
                "<ol>\n<li>\n<hr>\n</li>\n</ol>\n<p>tail</p>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }
}
