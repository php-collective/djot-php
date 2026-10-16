<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition term is a paragraph: the lines below it continue it until a
 * blank line, unless an unindented one opens a block (matches djot.js).
 */
class DefinitionTermContinuationTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testTermContinuation(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'unindented line' => [
                ": t\ntail",
                "<dl>\n<dt>t\ntail</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'indented line' => [
                ": t\n  tail",
                "<dl>\n<dt>t\ntail</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'one-space line' => [
                ": t\n tail",
                "<dl>\n<dt>t\ntail</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'term in a list item' => [
                "- : t\n  tail",
                "<ul>\n<li>\n<dl>\n<dt>t\ntail</dt>\n<dd>\n</dd>\n</dl>\n</li>\n</ul>\n",
            ],
            'block line ends the term' => [
                ": t\n# H",
                "<dl>\n<dt>t</dt>\n<dd>\n</dd>\n</dl>\n<section id=\"H\">\n<h1>H</h1>\n</section>\n",
            ],
            'definition after a blank line' => [
                ": t\n\n  def",
                "<dl>\n<dt>t</dt>\n<dd>\n<p>def</p>\n</dd>\n</dl>\n",
            ],
        ];
    }
}
