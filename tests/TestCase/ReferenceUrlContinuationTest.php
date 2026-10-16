<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference definition's URL continues only on lines that hold one more chunk
 * without inner whitespace and are indented past the definition (matches
 * djot.js).
 */
class ReferenceUrlContinuationTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testUrlContinuation(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'chunk continues the URL' => [
                "[r]: /u\n  /v\n\n[r][]",
                "<p><a href=\"/u/v\">r</a></p>\n",
            ],
            'line with a space is a paragraph' => [
                "[r]: /u\n  a b\n\n[r][]",
                "<p>a b</p>\n<p><a href=\"/u\">r</a></p>\n",
            ],
            'quote line is a quote' => [
                "[r]: /u\n  > tail\n\n[r][]",
                "<blockquote>\n<p>tail</p>\n</blockquote>\n<p><a href=\"/u\">r</a></p>\n",
            ],
            'same indentation as the definition' => [
                "  [r]: /u\n  tail\n\n[r][]",
                "<p>tail</p>\n<p><a href=\"/u\">r</a></p>\n",
            ],
            'deeper than an indented definition' => [
                "  [r]: /u\n     /v\n\n[r][]",
                "<p><a href=\"/u/v\">r</a></p>\n",
            ],
        ];
    }
}
