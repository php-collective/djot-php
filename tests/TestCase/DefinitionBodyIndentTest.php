<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body line needs only to be indented past the `:` marker
 * (matches djot.js).
 */
class DefinitionBodyIndentTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testDefinitionBody(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'one-space body' => [
                ": t\n\n x",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>x</p>\n</dd>\n</dl>\n",
            ],
            'one-space continuation' => [
                ": t\n\n  para\n more",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>para\nmore</p>\n</dd>\n</dl>\n",
            ],
            'indented block line stays in the body' => [
                ": t\n\n  para\n [r]: /u",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>para\n[r]: /u</p>\n</dd>\n</dl>\n",
            ],
            'unindented attribute line ends the list' => [
                ": t\n\n  > q\n{#x}\ntail",
                "<dl>\n<dt>t</dt>\n<dd>\n<blockquote>\n<p>q</p>\n</blockquote>\n</dd>\n</dl>\n<p id=\"x\">tail</p>\n",
            ],
        ];
    }
}
