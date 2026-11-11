<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A braced closer such as `*}` skips verbatim spans but not escaped
 * backticks, and an escaped marker does not close.
 */
class BracedCloserEscapeTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testBracedCloser(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'escaped backtick inside braced strong' => [
                '{*a \\` b*}',
                "<p><strong>a ` b</strong></p>\n",
            ],
            'escaped backtick inside braced highlight' => [
                '{=x \\` y=}',
                "<p><mark>x ` y</mark></p>\n",
            ],
            'escaped backslash before a backtick still opens verbatim' => [
                '{*a \\\\` b*}',
                "<p>{*a \\<code> b*}</code></p>\n",
            ],
            'escaped closer marker' => [
                '{*a \\*} b*}',
                "<p><strong>a *} b</strong></p>\n",
            ],
            'verbatim still hides a closer' => [
                '{*a `b*}` c*}',
                "<p><strong>a <code>b*}</code> c</strong></p>\n",
            ],
            'escaped backtick in a quote' => [
                '> {_x \\` y_}',
                "<blockquote>\n<p><em>x ` y</em></p>\n</blockquote>\n",
            ],
        ];
    }
}
