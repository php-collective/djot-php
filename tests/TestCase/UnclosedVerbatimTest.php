<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verbatim (and math) closes only at a backtick run of the same width and
 * otherwise runs to the end of the inline content; no delimiter pairs across it.
 */
class UnclosedVerbatimTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testUnclosedVerbatim(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'strong does not close inside unclosed verbatim' => [
                '*not strong `code* here',
                "<p>*not strong <code>code* here</code></p>\n",
            ],
            'emphasis does not close inside unclosed verbatim' => [
                '_a `b_ c',
                "<p>_a <code>b_ c</code></p>\n",
            ],
            'unclosed inline math' => [
                'Einstein derived $`e=mc^2',
                "<p>Einstein derived <span class=\"math inline\">\\(e=mc^2\\)</span></p>\n",
            ],
            'unclosed display math' => [
                'see $$`x+y',
                "<p>see <span class=\"math display\">\\[x+y\\]</span></p>\n",
            ],
            'unclosed math spans lines' => [
                "a \$`b\nc",
                "<p>a <span class=\"math inline\">\\(b\nc\\)</span></p>\n",
            ],
            'wider run is no closer' => [
                '*a `b``c* d',
                "<p>*a <code>b``c* d</code></p>\n",
            ],
            'math closer must match width' => [
                '$`a``b` c',
                "<p><span class=\"math inline\">\\(a``b\\)</span> c</p>\n",
            ],
            'braced strong skips verbatim' => [
                '{*x `code*} y` z',
                "<p>{*x <code>code*} y</code> z</p>\n",
            ],
            'braced highlight skips unclosed verbatim' => [
                '{=x ``code=}',
                "<p>{=x <code>code=}</code></p>\n",
            ],
            'quote before verbatim holding a quote' => [
                '> "x `code" y` z',
                "<blockquote>\n<p>“x <code>code\" y</code> z</p>\n</blockquote>\n",
            ],
            'closed verbatim still closes' => [
                '*a `b` c*',
                "<p><strong>a <code>b</code> c</strong></p>\n",
            ],
        ];
    }
}
