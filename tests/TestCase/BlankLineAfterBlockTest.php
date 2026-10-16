<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Blocks whose look-ahead stops at a real blank line. The empty string after
 * the final newline used to reach these exits; it is no longer a line.
 */
class BlankLineAfterBlockTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testBlankLineEndsLookAhead(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'list item attribute lines' => [
                "- a\n  {.c}\n  {.d}\n\nnext\n",
                "<ul>\n<li class=\"c d\">\na\n</li>\n</ul>\n<p>next</p>\n",
            ],
            'roman list items' => [
                "i. a\n\nii. b\n",
                "<ol type=\"i\">\n<li>\n<p>a</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ol>\n",
            ],
            'table with a row span' => [
                "| a |\n| ^ |\n\nx",
                "<table>\n<tr>\n<td rowspan=\"2\">a</td>\n</tr>\n<tr>\n</tr>\n</table>\n<p>x</p>\n",
            ],
        ];
    }
}
