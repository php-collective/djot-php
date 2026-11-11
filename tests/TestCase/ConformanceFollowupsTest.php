<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Empty-label references in quotes, attributes left by an item or a note,
 * trailing tabs in cells and whitespace-only lines in item code (matches djot.js).
 */
class ConformanceFollowupsTest extends TestCase
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
            'reference definition with an empty label in a quote' => [
                "> []: p\npara\n",
                "<blockquote>\n</blockquote>\n<p>para</p>\n",
            ],
            'reference definition with an empty label after quoted text' => [
                "> x\n>\n> []: <u>\nr\n",
                "<blockquote>\n<p>x</p>\n</blockquote>\n<p>r</p>\n",
            ],
            'attributes in an empty item go to the next item' => [
                "- {.a}\n- b\n",
                "<ul>\n<li>\n</li>\n<li class=\"a\">\nb\n</li>\n</ul>\n",
            ],
            'attributes in an empty ordered item go to the next item' => [
                "1. {#x}\n2. b\n   c\n",
                "<ol>\n<li>\n</li>\n<li id=\"x\">\nb\nc\n</li>\n</ol>\n",
            ],
            'attributes after a blank line in an item go to the next item' => [
                "- x\n\n  {.a}\n- b\n",
                "<ul>\n<li>\n<p>x</p>\n</li>\n<li class=\"a\">\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'blank line before the next item drops the attributes' => [
                "- {.a}\n\n- b\n",
                "<ul>\n<li>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'footnote definition consumes the attributes before it' => [
                "{.a}\n[^n]: x\n> q\n",
                "<blockquote>\n<p>q</p>\n</blockquote>\n",
            ],
            'footnote definition in an item consumes the attributes' => [
                "- {.a}\n  [^n]: x\n- b\n",
                "<ul>\n<li>\n</li>\n<li>\nb\n</li>\n</ul>\n",
            ],
            'tab after an escaped space in a cell' => [
                "| a\\ \t|\n",
                "<table>\n<tr>\n<td>a&nbsp;\t</td>\n</tr>\n</table>\n",
            ],
            'tab and spaces after the text of a cell' => [
                "| a \t |\n",
                "<table>\n<tr>\n<td>a \t</td>\n</tr>\n</table>\n",
            ],
            'whitespace-only line in a raw block in a tight item' => [
                "- ``` =html\n  x\n   \n  y\n  ```\n",
                "<ul>\n<li>\nx\n \ny\n</li>\n</ul>\n",
            ],
            'whitespace-only line in a code block in a tight item' => [
                "- ```\n  x\n    \n  ```\n",
                "<ul>\n<li>\n<pre><code>x\n  \n</code></pre>\n</li>\n</ul>\n",
            ],
            'whitespace-only line in a code block in a loose item' => [
                "- a\n\n  ```\n  x\n     \n  y\n  ```\n",
                "<ul>\n<li>\n<p>a</p>\n<pre><code>x\n   \ny\n</code></pre>\n</li>\n</ul>\n",
            ],
            'whitespace-only line in a raw block in a loose item' => [
                "- a\n\n  ``` =html\n  x\n    \n  y\n  ```\n",
                "<ul>\n<li>\n<p>a</p>\nx\n  \ny\n</li>\n</ul>\n",
            ],
        ];
    }
}
