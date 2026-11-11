<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline cases where djot-php differed from djot.js.
 */
class InlineParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'F5 unmatched double quote after a digit' => [
                "a 12\" pizza\n",
                "<p>a 12“ pizza</p>\n",
            ],
            'F5 unmatched double quote inside a word' => [
                "foo\"bar\n",
                "<p>foo“bar</p>\n",
            ],
            'F5 unmatched single quote at the end' => [
                "x '\n",
                "<p>x ’</p>\n",
            ],
            'F5 unmatched single quote between spaces' => [
                "a ' b\n",
                "<p>a ’ b</p>\n",
            ],
            'F5 two unmatched single quotes' => [
                "''\n",
                "<p>’’</p>\n",
            ],
            'F5 two unmatched double quotes' => [
                "\"\"\n",
                "<p>““</p>\n",
            ],
            'F5 apostrophe in a word closes an open quote' => [
                "x 'a it's\n",
                "<p>x ‘a it’s</p>\n",
            ],
            'F5 matched quotes keep their pairing' => [
                "'a' and \"b\" it's\n",
                "<p>‘a’ and “b” it’s</p>\n",
            ],
            'F10 trailing space in a term' => [
                ": term \n\n  def\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>def</p>\n</dd>\n</dl>\n",
            ],
            'F10 trailing space on a continued term' => [
                ": a\n  b \n\n  d\n",
                "<dl>\n<dt>a\nb</dt>\n<dd>\n<p>d</p>\n</dd>\n</dl>\n",
            ],
            'F11 spaces around a destination' => [
                "[x]( http://y )\n",
                "<p><a href=\" http://y \">x</a></p>\n",
            ],
            'F11 spaces around an image source' => [
                "![a]( b )\n",
                "<p><img alt=\"a\" src=\" b \"></p>\n",
            ],
            'F11 indentation after a break in a destination' => [
                "[x](y \n  z)\n",
                "<p><a href=\"y z\">x</a></p>\n",
            ],
            'F16 dashes in a failed attribute' => [
                "{1--}\n\n{1-}\n",
                "<p>{1--}</p>\n<p>{1-}</p>\n",
            ],
            'F16 dashes after a class in a failed attribute' => [
                "{.a --}\n",
                "<p>{.a --}</p>\n",
            ],
            'F16 dots in an unclosed attribute' => [
                "{...\n",
                "<p>{...</p>\n",
            ],
            'F16 dashes inside a key' => [
                "w{1--x} y\n",
                "<p>w{1--x} y</p>\n",
            ],
            'F16 dashes after a failed attribute key' => [
                "{a -- b}\n",
                "<p>{a – b}</p>\n",
            ],
            'F16 dashes before a closing brace' => [
                "x --}\n",
                "<p>x --}</p>\n",
            ],
            'F25 comment-only attribute with a blank' => [
                "{ %}\npara\n",
                "<p>para</p>\n",
            ],
            'F25 empty highlight' => [
                "{==}\n",
                "<p>{==}</p>\n",
            ],
            'F25 empty insert and later closer' => [
                "{++}y+}\n",
                "<p><ins>+}y</ins></p>\n",
            ],
            'F25 empty delete' => [
                "{--}\n",
                "<p>{--}</p>\n",
            ],
            'F25 unclosed verbatim trailing space' => [
                "x `y \n",
                "<p>x <code>y</code></p>\n",
            ],
            'F25 unclosed math trailing space' => [
                "x \$`y \n",
                "<p>x <span class=\"math inline\">\\(y\\)</span></p>\n",
            ],
            'F25 unclosed fence in a definition keeps a blank line' => [
                ": ```\n\n",
                "<dl>\n<dt></dt>\n<dd>\n<pre><code>\n</code></pre>\n</dd>\n</dl>\n",
            ],
            'F25 empty reference label' => [
                "[]: /u\n\n[x][]\n",
                "<p><a>x</a></p>\n",
            ],
            'F25 empty reference label is not used' => [
                "[][]\n\n[]: /u\n",
                "<p><a></a></p>\n",
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testMatchesDjotJs(string $input, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($input));
    }
}
