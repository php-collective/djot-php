<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every paragraph of a tight list item renders without a <p>, not only the
 * first one (matches djot.js).
 */
class TightItemParagraphsTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testTightItemParagraphs(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'paragraph after a code block' => [
                "- ```\n  x\n  ```\n  para",
                "<ul>\n<li>\n<pre><code>x\n</code></pre>\npara\n</li>\n</ul>\n",
            ],
            'paragraph after a table' => [
                "- | a |\n  tail",
                "<ul>\n<li>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\ntail\n</li>\n</ul>\n",
            ],
            'paragraph after a nested list' => [
                "- A\n\n  - B\n\n  C",
                "<ul>\n<li>\nA\n<ul>\n<li>\nB\n</li>\n</ul>\nC\n</li>\n</ul>\n",
            ],
            'loose list keeps its paragraphs' => [
                "- a\n\n  b",
                "<ul>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n</ul>\n",
            ],
            'paragraph with attributes keeps its tag, as the first one does' => [
                "- ```\n  x\n  ```\n  {#p}\n  para",
                "<ul>\n<li>\n<pre><code>x\n</code></pre>\n<p id=\"p\">para</p>\n</li>\n</ul>\n",
            ],
        ];
    }
}
