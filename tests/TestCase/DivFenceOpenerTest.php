<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A div fence takes at most one class name after its colons; anything else
 * leaves the line as paragraph text, as in djot.js.
 */
class DivFenceOpenerTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testDivFenceOpener(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'two words' => [
                "::: warning extra\ntext\n:::",
                "<p>::: warning extra\ntext\n:::</p>\n",
            ],
            'star' => [
                "::: *\ntext\n:::",
                "<p>::: *\ntext\n:::</p>\n",
            ],
            'angle bracket glued' => [
                ":::>\ntext\n:::",
                "<p>:::&gt;\ntext\n:::</p>\n",
            ],
            'paren glued' => [
                ":::(\ntext\n:::",
                "<p>:::(\ntext\n:::</p>\n",
            ],
            'non-ASCII word' => [
                "::: é\ntext\n:::",
                "<p>::: é\ntext\n:::</p>\n",
            ],
            'in block quote' => [
                "> ::: a b\n> text",
                "<blockquote>\n<p>::: a b\ntext</p>\n</blockquote>\n",
            ],
            'single class' => [
                "::: warning\ntext\n:::",
                "<div class=\"warning\">\n<p>text</p>\n</div>\n",
            ],
            'glued class with dash and underscore' => [
                ":::a_b-c\ntext\n:::",
                "<div class=\"a_b-c\">\n<p>text</p>\n</div>\n",
            ],
            'trailing whitespace' => [
                "::: warning \ntext\n:::",
                "<div class=\"warning\">\n<p>text</p>\n</div>\n",
            ],
            'attribute block extension still opens a div' => [
                "::: {.x}\ntext\n:::",
                "<div class=\"x\">\n<p>text</p>\n</div>\n",
            ],
        ];
    }
}
