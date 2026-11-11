<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block structure cases where djot-php differed from djot.js.
 */
class BlockStructureParityTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function emptyTaskItemProvider(): array
    {
        return [
            'unchecked then text' => ["- [ ]\n- [ ] two\n", 2],
            'checked alone' => ["- [x]\n", 1],
            'star marker' => ["* [ ]\n* [x]\n* [ ] c\n", 3],
        ];
    }

    #[DataProvider('emptyTaskItemProvider')]
    public function testEmptyTaskItemIsATaskInTheSameList(string $input, int $items): void
    {
        $children = (new DjotConverter())->parse($input)->getChildren();

        $this->assertCount(1, $children);
        $list = $children[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame(ListBlock::TYPE_TASK, $list->getListType());
        $this->assertCount($items, $list->getChildren());
        foreach ($list->getChildren() as $item) {
            $this->assertInstanceOf(ListItem::class, $item);
            $this->assertTrue($item->isTask());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'escaped space before the closing pipe' => [
                "| C:\\ |\n",
                "<table>\n<tr>\n<td>C:&nbsp;</td>\n</tr>\n</table>\n",
            ],
            'escaped space and padding' => [
                "| a \\  |\n",
                "<table>\n<tr>\n<td>a &nbsp;</td>\n</tr>\n</table>\n",
            ],
            'escaped closing pipe is no row' => [
                "| a | b \\|\n",
                "<p>| a | b |</p>\n",
            ],
            'escaped closing pipe after a backslash is no row' => [
                "| a \\\\|\n",
                "<p>| a \\|</p>\n",
            ],
            'break after a blank line loosens the item' => [
                "- a\n\n  - - -\n",
                "<ul>\n<li>\n<p>a</p>\n<hr>\n</li>\n</ul>\n",
            ],
            'star break after a blank line loosens the item' => [
                "- a\n\n  * * *\n",
                "<ul>\n<li>\n<p>a</p>\n<hr>\n</li>\n</ul>\n",
            ],
            'sublist after a blank line keeps the item tight' => [
                "- a\n\n  - b\n",
                "<ul>\n<li>\na\n<ul>\n<li>\nb\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'indented second row' => [
                "| a |\n | b |\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n<tr>\n<td>b</td>\n</tr>\n</table>\n",
            ],
            'tab-indented second row' => [
                "| a |\n\t| b |\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n<tr>\n<td>b</td>\n</tr>\n</table>\n",
            ],
            'indented separator row' => [
                "| a |\n |---|\n | b |\n",
                "<table>\n<tr>\n<th>a</th>\n</tr>\n<tr>\n<td>b</td>\n</tr>\n</table>\n",
            ],
            'backticks inside a div paragraph do not hide the closer' => [
                "::: note\ntext\n```\n:::\n",
                "<div class=\"note\">\n<p>text\n<code></code></p>\n</div>\n",
            ],
            'backticks inside a list item paragraph do not hide the closer' => [
                ":::\n1. s\n ```\n:::\n",
                "<div>\n<ol>\n<li>\ns\n<code></code>\n</li>\n</ol>\n</div>\n",
            ],
            'a fence after a blank line still hides the closer' => [
                ":::\ntext\n\n```\n:::\n```\n:::\n",
                "<div>\n<p>text</p>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a heading still hides the closer' => [
                ":::\n# heading\n```\n:::\n```\n:::\n",
                "<div>\n<h1 id=\"heading\">heading</h1>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a thematic break still hides the closer' => [
                ":::\n---\n```\n:::\n```\n:::\n",
                "<div>\n<hr>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a table row still hides the closer' => [
                ":::\n| t |\n```\n:::\n```\n:::\n",
                "<div>\n<table>\n<tr>\n<td>t</td>\n</tr>\n</table>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after an attribute line still hides the closer' => [
                ":::\n{.c}\n```\n:::\n```\n:::\n",
                "<div>\n<pre class=\"c\"><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a heading continuation still hides the closer' => [
                ":::\n# h\nmore\n```\n:::\n```\n:::\n",
                "<div>\n<h1 id=\"h-more\">h\nmore</h1>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'an unindented fence after a list item still hides the closer' => [
                ":::\n- i\n```\n:::\n```\n:::\n",
                "<div>\n<ul>\n<li>\ni\n</li>\n</ul>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a quote still hides the closer' => [
                ":::\n> q\n```\n:::\n```\n:::\n",
                "<div>\n<blockquote>\n<p>q</p>\n</blockquote>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after an item paragraph past a blank line still hides the closer' => [
                ":::\n- i\n\n  para\n```\n:::\n```\n:::\n",
                "<div>\n<ul>\n<li>\n<p>i</p>\n<p>para</p>\n</li>\n</ul>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'a fence after a quote that follows a list item still hides the closer' => [
                ":::\n- a\n> q\n  ```\n:::\n  ```\n:::\n",
                "<div>\n<ul>\n<li>\na\n</li>\n</ul>\n<blockquote>\n<p>q</p>\n</blockquote>\n<pre><code>:::\n</code></pre>\n</div>\n",
            ],
            'an attribute line after a list item still lets a fence hide the closer' => [
                ":::\n1. c\n  - b\n{.c}\n  ```\n```\n:::\n",
                "<div>\n<ol>\n<li>\nc\n- b\n</li>\n</ol>\n<pre class=\"c\"><code></code></pre>\n</div>\n",
            ],
            'closing pipe inside an open code span ends the table' => [
                "| a |\n| `a |\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p>| <code>a |</code></p>\n",
            ],
            'closing pipe inside an open code span on an indented row ends the table' => [
                "| a |\n | `a |\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p>| <code>a |</code></p>\n",
            ],
            'escaped closing pipe ends the table' => [
                "| a |\n| b \\|\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p>| b |</p>\n",
            ],
            'escaped closing pipe on an indented row ends the table' => [
                "| a |\n | b \\|\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n<p>| b |</p>\n",
            ],
            'line before a sublist past a task box' => [
                "- [ ] *\n   `x`\n",
                "<ul class=\"task-list\">\n<li>\n<input disabled=\"\" type=\"checkbox\"/>\n<ul>\n<li>\n</li>\n</ul>\n<code>x</code>\n</li>\n</ul>\n",
            ],
            'paragraph before a sublist past a task box' => [
                "- [ ] - a\n\n    b\n",
                "<ul class=\"task-list\">\n<li>\n<input disabled=\"\" type=\"checkbox\"/>\n<ul>\n<li>\na\n</li>\n</ul>\nb\n</li>\n</ul>\n",
            ],
            'paragraph before a sublist past extra marker spaces' => [
                "-   - a\n\n    b\n",
                "<ul>\n<li>\n<ul>\n<li>\na\n</li>\n</ul>\nb\n</li>\n</ul>\n",
            ],
            'paragraph inside a sublist past extra marker spaces' => [
                "-   - a\n\n      b\n",
                "<ul>\n<li>\n<ul>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'ordered sublist past a task box' => [
                "- [x] 1. a\n\n      b\n",
                "<ul class=\"task-list\">\n<li>\n<input disabled=\"\" type=\"checkbox\" checked=\"\"/>\n<ol>\n<li>\na\n</li>\n</ol>\nb\n</li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testMatchesDjotJs(string $input, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($input));
    }
}
