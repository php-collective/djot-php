<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * In a list item's content after a blank line, an unindented line continues the
 * item only while a paragraph is open. After a fence, div or other block that
 * holds no open paragraph, the line ends the list (matches djot.js).
 */
class ListItemLazyAfterBlockTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    #[DataProvider('noOpenParagraphProvider')]
    public function testUnindentedLineWithoutOpenParagraphEndsList(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function noOpenParagraphProvider(): array
    {
        return [
            'open code fence' => [
                "- a\n\n  ```\n  code\ntext",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'closed code fence' => [
                "- a\n\n  ```\n  code\n  ```\ntext",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'tilde fence, two lines' => [
                "- a\n\n  ~~~\n  code\ntext\n  more",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n<p>text\nmore</p>\n",
            ],
            'fence inside a quote' => [
                "- a\n\n  > ```\n  > code\ntext",
                "<ul>\n<li>\n<p>a</p>\n<blockquote>\n<pre><code>code\n</code></pre>\n</blockquote>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'fence inside a div' => [
                "- a\n\n  ::: b\n\n  ```\n  code\ntext",
                "<ul>\n<li>\n<p>a</p>\n<div class=\"b\">\n<pre><code>code\n</code></pre>\n</div>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'ordered item' => [
                "1. a\n\n   ```\n   code\ntext",
                "<ol>\n<li>\n<p>a</p>\n<pre><code>code\n</code></pre>\n</li>\n</ol>\n<p>text</p>\n",
            ],
            'closed div' => [
                "- a\n\n  ::: d\n  x\n  :::\ntext",
                "<ul>\n<li>\n<p>a</p>\n<div class=\"d\">\n<p>x</p>\n</div>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'table row' => [
                "- a\n\n  | x |\ntext",
                "<ul>\n<li>\n<p>a</p>\n<table>\n<tr>\n<td>x</td>\n</tr>\n</table>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'thematic break' => [
                "- a\n\n  ---\ntext",
                "<ul>\n<li>\n<p>a</p>\n<hr>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'open fenced comment' => [
                "- a\n\n  %%%\n  c\ntext",
                "<ul>\n<li>\n<p>a</p>\n</li>\n</ul>\n<p>text</p>\n",
            ],
            'reference definition' => [
                "- a\n\n  [r]: u\ntext",
                "<ul>\n<li>\n<p>a</p>\n</li>\n</ul>\n<p>text</p>\n",
            ],
        ];
    }

    #[DataProvider('openParagraphProvider')]
    public function testUnindentedLineStillContinuesOpenParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function openParagraphProvider(): array
    {
        return [
            'open paragraph' => [
                "- a\n\n  b\ntext",
                "<ul>\n<li>\n<p>a</p>\n<p>b\ntext</p>\n</li>\n</ul>\n",
            ],
            'paragraph inside an open div' => [
                "- a\n\n  ::: b\n  x\ntext",
                "<ul>\n<li>\n<p>a</p>\n<div class=\"b\">\n<p>x\ntext</p>\n</div>\n</li>\n</ul>\n",
            ],
            'paragraph after a closed fence' => [
                "- a\n\n  ```\n  c\n  ```\n  d\ntext",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>c\n</code></pre>\n<p>d\ntext</p>\n</li>\n</ul>\n",
            ],
            'sibling item after a closed fence' => [
                "- a\n\n  ```\n  code\n  ```\n- c",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>code\n</code></pre>\n</li>\n<li>\n<p>c</p>\n</li>\n</ul>\n",
            ],
            'unclosed fence in a nested item ended by a dedent' => [
                "- a\n\n  - ```\n    code\n  next\ntext",
                "<ul>\n<li>\na\n<ul>\n<li>\n<pre><code>code\n</code></pre>\n</li>\n</ul>\n<p>next\ntext</p>\n</li>\n</ul>\n",
            ],
        ];
    }
}
