<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line of bare quote markers ends a quoted paragraph inside a list item or
 * definition, so an unindented line after it is no lazy continuation.
 */
class QuoteMarkerEndsItemParagraphTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testQuoteMarkerLine(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'list item' => [
                "- > a\n  >\nb",
                "<ul>\n<li>\n<blockquote>\n<p>a</p>\n</blockquote>\n</li>\n</ul>\n<p>b</p>\n",
            ],
            'ordered list item' => [
                "1. > a\n   >\nb",
                "<ol>\n<li>\n<blockquote>\n<p>a</p>\n</blockquote>\n</li>\n</ol>\n<p>b</p>\n",
            ],
            'definition' => [
                ": t\n\n  > a\n  >\nb",
                "<dl>\n<dt>t</dt>\n<dd>\n<blockquote>\n<p>a</p>\n</blockquote>\n</dd>\n</dl>\n<p>b</p>\n",
            ],
            'nested quotes' => [
                "- > > a\n  >\nb",
                "<ul>\n<li>\n<blockquote>\n<blockquote>\n<p>a</p>\n</blockquote>\n</blockquote>\n</li>\n</ul>\n<p>b</p>\n",
            ],
            'after a lazy line' => [
                "- > a\nb\n  >\nc",
                "<ul>\n<li>\n<blockquote>\n<p>a\nb</p>\n</blockquote>\n</li>\n</ul>\n<p>c</p>\n",
            ],
            'quote marker after plain text stays text' => [
                "- a\n  >\nb",
                "<ul>\n<li>\na\n&gt;\nb\n</li>\n</ul>\n",
            ],
            'code block after the quote' => [
                ": t\n\n  > para\n\n  ```\n  x\n  ```\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n<blockquote>\n<p>para</p>\n</blockquote>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n<p>tail</p>\n",
            ],
        ];
    }
}
