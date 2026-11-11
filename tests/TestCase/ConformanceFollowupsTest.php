<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Empty-label references in quotes and whitespace-only lines in item code (as djot.js),
 * and trailing tabs trimmed from cells (djot.js keeps them, see jgm/djot.js#176).
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
            'tab after an escaped space in a cell is trimmed' => [
                "| a\\ \t|\n",
                "<table>\n<tr>\n<td>a&nbsp;</td>\n</tr>\n</table>\n",
            ],
            'tab and spaces after the text of a cell are trimmed' => [
                "| a \t |\n",
                "<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n",
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
