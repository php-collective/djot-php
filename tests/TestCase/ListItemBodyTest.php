<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list item's blocks are parsed in one piece, so a container its first block
 * opens keeps the indented lines after a blank line.
 *
 * The definition rows keep `<p>` inside `<dd>`: djot.js renders it bare there
 * because the definition list takes the outer list's tightness, while a
 * definition list on its own renders `<p>` in both.
 */
class ListItemBodyTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testItemBody(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'definition continues after a blank line' => [
                "- : t\n\n    tail\n- two",
                "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>tail</p>\n</dd>\n</dl>\n</li>\n<li>\ntwo\n</li>\n</ul>\n",
            ],
            'definition in an ordered item' => [
                "1. : t\n\n     para\n2. two",
                "<ol>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n</dd>\n</dl>\n</li>\n<li>\ntwo\n</li>\n</ol>\n",
            ],
            'definition then item paragraph' => [
                "- : t\n\n    deep\n\n  back",
                "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>deep</p>\n</dd>\n</dl>\nback\n</li>\n</ul>\n",
            ],
            'note continues after a blank line' => [
                "[^n]\n\n- [^n]: para\n\n    tail",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<ul>\n<li>\n</li>\n</ul>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para</p>\n<p>tail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'note ends at the item column' => [
                "[^n]\n\n- [^n]: para\n\n  tail",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<ul>\n<li>\n<p>tail</p>\n</li>\n</ul>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'reference definition with a shallow body' => [
                "[r][]\n\n- para\n\n [r]: /u\n  tail",
                "<p><a href=\"/utail\">r</a></p>\n<ul>\n<li>\n<p>para</p>\n</li>\n</ul>\n",
            ],
            'quote then paragraph' => [
                "- > q\n\n  more",
                "<ul>\n<li>\n<blockquote>\n<p>q</p>\n</blockquote>\n<p>more</p>\n</li>\n</ul>\n",
            ],
            'code then paragraph' => [
                "- ```\n  code\n  ```\n\n  more\n- two",
                "<ul>\n<li>\n<pre><code>code\n</code></pre>\n<p>more</p>\n</li>\n<li>\n<p>two</p>\n</li>\n</ul>\n",
            ],
            'sublist after a blank stays tight' => [
                "- a\n\n  - sub\n- two",
                "<ul>\n<li>\na\n<ul>\n<li>\nsub\n</li>\n</ul>\n</li>\n<li>\ntwo\n</li>\n</ul>\n",
            ],
            'sublist then item paragraph' => [
                "- para\n\n  - sub\n\n  more",
                "<ul>\n<li>\npara\n<ul>\n<li>\nsub\n</li>\n</ul>\nmore\n</li>\n</ul>\n",
            ],
            'paragraph after a sublist, blank before the next item' => [
                "1. - inner\n\n   more\n\n1. two",
                "<ol>\n<li>\n<ul>\n<li>\ninner\n</li>\n</ul>\n<p>more</p>\n</li>\n<li>\n<p>two</p>\n</li>\n</ol>\n",
            ],
            'deeper paragraph then item paragraph' => [
                "- a\n\n    deep\n\n  back",
                "<ul>\n<li>\n<p>a</p>\n<p>deep</p>\n<p>back</p>\n</li>\n</ul>\n",
            ],
            'blank after a sublist before the next item' => [
                "- a\n\n  - sub\n\n- two",
                "<ul>\n<li>\na\n<ul>\n<li>\nsub\n</li>\n</ul>\n</li>\n<li>\ntwo\n</li>\n</ul>\n",
            ],
            'blank after a marker-line sublist' => [
                "- - x\n\n- b",
                "<ul>\n<li>\n<ul>\n<li>\nx\n</li>\n</ul>\n</li>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'lazy line in a later paragraph' => [
                "- a\n\n  more\nlazy",
                "<ul>\n<li>\n<p>a</p>\n<p>more\nlazy</p>\n</li>\n</ul>\n",
            ],
            'loose list from paragraphs' => [
                "- a\n\n  b\n- c",
                "<ul>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n<li>\n<p>c</p>\n</li>\n</ul>\n",
            ],
            'blank between items' => [
                "- a\n\n- b",
                "<ul>\n<li>\n<p>a</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n",
            ],
        ];
    }
}
