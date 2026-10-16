<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list opened on a footnote's marker line keeps its column, so the lines below it are measured against the list marker (matches djot.js).
 */
class FootnoteFirstLineListTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testFirstLineList(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'thematic break ends the item' => [
                "[^n]\n\n[^n]: 1. para\n      * * *",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>\npara\n</li>\n</ol>\n<hr>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'heading ends the item' => [
                "[^n]\n\n[^n]: 1. para\n      # H",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>\npara\n</li>\n</ol>\n<h1 id=\"H\">H</h1>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'table after a bullet item' => [
                "[^n]\n\n[^n]: - para\n   | a |",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\npara\n</li>\n</ul>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'table after a definition' => [
                "[^n]\n\n[^n]: : t\n   | a |",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t</dt>\n<dd>\n</dd>\n</dl>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'paragraph at the marker column leaves the item' => [
                "[^n]\n\n[^n]: 1. para\n\n      more",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>\npara\n</li>\n</ol>\n<p>more<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'paragraph past the marker column stays in the item' => [
                "[^n]\n\n[^n]: 1. para\n\n         more",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>\n<p>para</p>\n<p>more</p>\n</li>\n</ol>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'sibling at the body indentation' => [
                "[^n]\n\n[^n]: - a\n  - b",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\na\n</li>\n<li>\nb\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'lazy line left of the list' => [
                "[^n]\n\n[^n]: - a\n\n        b\nc",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\n<p>a</p>\n<p>b\nc</p>\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'lazy line left of an indented top-level list' => [
                "  - a\n\n    b\nc",
                "<ul>\n<li>\n<p>a</p>\n<p>b\nc</p>\n</li>\n</ul>\n",
            ],
        ];
    }
}
