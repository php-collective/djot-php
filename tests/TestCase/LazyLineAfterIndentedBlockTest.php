<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An indented block that holds no paragraph (thematic break, table, reference
 * definition) leaves nothing for an unindented line to extend, so the line
 * ends the container (matches djot.js).
 */
class LazyLineAfterIndentedBlockTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testLineAfterIndentedBlock(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'thematic break in a definition' => [
                ": t\n\n   * * *\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n<hr>\n</dd>\n</dl>\n<p>tail</p>\n",
            ],
            'table in a definition' => [
                ": t\n\n   | a |\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</dd>\n</dl>\n<p>tail</p>\n",
            ],
            'reference definition in a definition' => [
                ": t\n\n   [r]: /u\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n</dd>\n</dl>\n<p>tail</p>\n",
            ],
            'paragraph still takes the line' => [
                ": t\n\n   para\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>para\ntail</p>\n</dd>\n</dl>\n",
            ],
        ];
    }
}
