<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph in a footnote takes following lines that omit the indentation,
 * as in block quotes and list items. A line that opens a block, or follows a
 * block without an open paragraph, ends the footnote (matches djot.js).
 */
class FootnoteLazyContinuationTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    #[DataProvider('lazyLineProvider')]
    public function testUnindentedLineContinuesFootnoteParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lazyLineProvider(): array
    {
        return [
            'unindented line' => [
                "x[^n]\n\n[^n]: a\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'two unindented lines' => [
                "x[^n]\n\n[^n]: a\ntail\nmore",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\ntail\nmore<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'after an indented line' => [
                "x[^n]\n\n[^n]: a\n  b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\nb\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'paragraph after a blank line' => [
                "x[^n]\n\n[^n]: a\n\n  b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a</p>\n<p>b\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'content starting on the next line' => [
                "x[^n]\n\n[^n]:\n  a\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'second definition' => [
                "x[^n] y[^m]\n\n[^n]: a\n[^m]: b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> y<a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n<li id=\"fn2\">\n<p>b\ntail<a href=\"#fnref2\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'list item paragraph' => [
                "x[^n]\n\n[^n]: - a\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\na\ntail\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'quote paragraph' => [
                "x[^n]\n\n[^n]: > q\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<blockquote>\n<p>q\ntail</p>\n</blockquote>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'two lines after a quote paragraph' => [
                "x[^n]\n\n[^n]: > q\ntail\nmore",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<blockquote>\n<p>q\ntail\nmore</p>\n</blockquote>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'two lines after a list item paragraph' => [
                "x[^n]\n\n[^n]: - a\ntail\nmore",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\na\ntail\nmore\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'paragraph after a quote' => [
                "x[^n]\n\n[^n]: > q\n\n  b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<blockquote>\n<p>q</p>\n</blockquote>\n<p>b\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'paragraph after a list' => [
                "x[^n]\n\n[^n]: - a\n\n  b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\na\n</li>\n</ul>\n<p>b\ntail<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'second list item paragraph' => [
                "x[^n]\n\n[^n]: - a\n  - b\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>\na\n</li>\n<li>\nb\ntail\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'quote marker without a space is text' => [
                "x[^n]\n\n[^n]: a\n>there",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\n&gt;there<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'pipe that is no table row is text' => [
                "x[^n]\n\n[^n]: a\n|pipe",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a\n|pipe<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }

    #[DataProvider('endingLineProvider')]
    public function testUnindentedLineEndsFootnote(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endingLineProvider(): array
    {
        return [
            'blank line' => [
                "x[^n]\n\n[^n]: a\n\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>tail</p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'empty definition' => [
                "x[^n]\n\n[^n]:\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>tail</p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'list marker' => [
                "x[^n]\n\n[^n]: a\n- tail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<ul>\n<li>\ntail\n</li>\n</ul>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'heading' => [
                "x[^n]\n\n[^n]: a\n# h",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section id=\"h\">\n<h1>h</h1>\n</section>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'quote' => [
                "x[^n]\n\n[^n]: a\n> q",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<blockquote>\n<p>q</p>\n</blockquote>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'code fence' => [
                "x[^n]\n\n[^n]: a\n```\ncode\n```",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<pre><code>code\n</code></pre>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'div' => [
                "x[^n]\n\n[^n]: a\n::: d\ny\n:::",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<div class=\"d\">\n<p>y</p>\n</div>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'thematic break' => [
                "x[^n]\n\n[^n]: a\n---",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<hr>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'table row' => [
                "x[^n]\n\n[^n]: a\n| t |",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<table>\n<tr>\n<td>t</td>\n</tr>\n</table>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'reference definition' => [
                "x[^n]\n\n[^n]: a\n[r]: u",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'open code fence in the footnote' => [
                "x[^n]\n\n[^n]: a\n\n  ```\n  code\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>tail</p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a</p>\n<pre><code>code\n</code></pre>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'closed code fence in the footnote' => [
                "x[^n]\n\n[^n]: a\n\n  ```\n  code\n  ```\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>tail</p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>a</p>\n<pre><code>code\n</code></pre>\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'reference definition in the footnote' => [
                "x[^n]\n\n[^n]: [r]: u\ntail",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>tail</p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }
}
