<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use Djot\Node\Inline\EscapedText;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Issue301ParityTest extends TestCase
{
    #[DataProvider('parityProvider')]
    public function testDjotJsParity(string $input, string $expected, bool $interrupt = false): void
    {
        foreach (
            [
                new DjotConverter(blocksInterruptParagraphs: $interrupt),
                new DjotConverter(parser: new BlockParser(blocksInterruptParagraphs: $interrupt)),
            ] as $converter
        ) {
            $actual = $converter->convert($input);
            self::assertSame($this->normalize($expected), $this->normalize($actual));
        }
    }

    #[DataProvider('delimiterRunProvider')]
    public function testDelimiterRuns(string $input, string $expected): void
    {
        $this->testDjotJsParity($input, $expected);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function delimiterRunProvider(): array
    {
        $cases = [];
        foreach (['_' => 'em', '*' => 'strong', '^' => 'sup', '~' => 'sub'] as $delimiter => $tag) {
            $cases[$tag . ' literal braced opener before closing run'] = [
                'text ' . $delimiter . '{' . $delimiter . $delimiter,
                '<p>text <' . $tag . '>{' . $delimiter . '</' . $tag . '></p>',
            ];
            $cases[$tag . ' escaped brace before closing run'] = [
                'text ' . $delimiter . '\\{' . $delimiter . $delimiter,
                '<p>text <' . $tag . '>{</' . $tag . '>' . $delimiter . '</p>',
            ];
            foreach (['space' => ' ', 'tab' => "\t", 'newline' => "\n"] as $name => $space) {
                $content = $delimiter . $space . 'a';
                $cases[$tag . ' run before ' . $name] = [
                    'text ' . $delimiter . $content . $delimiter . "\n",
                    '<p>text <' . $tag . '>' . $content . '</' . $tag . "></p>\n",
                ];
            }
            $cases[$tag . ' nested run before space'] = [
                'text ' . str_repeat($delimiter, 3) . ' a' . str_repeat($delimiter, 2) . "\n",
                '<p>text <' . $tag . '><' . $tag . '>' . $delimiter . ' a</' . $tag . '></' . $tag . "></p>\n",
            ];
            $cases[$tag . ' excess closing delimiter'] = [
                'text ' . str_repeat($delimiter, 2) . ' a' . str_repeat($delimiter, 2) . "\n",
                '<p>text <' . $tag . '>' . $delimiter . ' a</' . $tag . '>' . $delimiter . "</p>\n",
            ];
            $cases[$tag . ' run before braced closer'] = [
                'text ' . str_repeat($delimiter, 2) . '}a' . $delimiter . "\n",
                '<p>text <' . $tag . '>' . $delimiter . '}a</' . $tag . "></p>\n",
            ];
            $cases[$tag . ' later opener survives failed run'] = [
                'text ' . str_repeat($delimiter, 2) . 'a' . $delimiter . "\n",
                '<p>text ' . $delimiter . '<' . $tag . '>a</' . $tag . "></p>\n",
            ];
        }

        return $cases;
    }

    private function normalize(string $html): string
    {
        return trim(preg_replace('/>\s+</', '><', $html) ?? $html);
    }

    #[DataProvider('attributeWordProvider')]
    public function testAttributeWords(string $input, string $expected): void
    {
        $this->testDjotJsParity($input, $expected);
    }

    public function testAttributeWordKeepsEscapesAndParentLinks(): void
    {
        $input = 'foo\\*bar{.c}';
        $converter = new DjotConverter();
        $paragraph = $converter->parse($input)->getChildren()[0];
        $span = $paragraph->getChildren()[0];
        self::assertInstanceOf(Span::class, $span);
        self::assertSame($paragraph, $span->getParent());
        $children = $span->getChildren();
        self::assertCount(3, $children);
        self::assertInstanceOf(Text::class, $children[0]);
        self::assertInstanceOf(EscapedText::class, $children[1]);
        self::assertInstanceOf(Text::class, $children[2]);
        foreach ($children as $child) {
            self::assertSame($span, $child->getParent());
        }
        $roundTrip = new DjotConverter(roundTripMode: true);
        $back = (new HtmlToDjot(trustedRoundTrip: true))->convert($roundTrip->convert($input));
        self::assertSame('[foo\\*bar]{.c}', trim($back));
        self::assertSame($converter->convert($input), $converter->convert($back));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function attributeWordProvider(): array
    {
        $cases = [
            'unmatched strong delimiter' => ['foo*bar{.c}', '<p><span class="c">foo*bar</span></p>'],
            'literal brackets' => ['x[y]z{.c}', '<p><span class="c">x[y]z</span></p>'],
            'escaped punctuation within word' => ['foo\\*bar{.c}', '<p><span class="c">foo*bar</span></p>'],
            'ordinary punctuation' => ['a.b{.c}', '<p><span class="c">a.b</span></p>'],
            'escaped punctuation alone' => ['\\*{.c}', '<p><span class="c">*</span></p>'],
            'non ASCII word' => ['foo привет{.ru}', '<p>foo <span class="ru">привет</span></p>'],
            'preceding emphasis' => ['_e_x{.c}', '<p><em>e</em><span class="c">x</span></p>'],
            'preceding span' => ['[e]{}x{.c}', '<p><span>e</span><span class="c">x</span></p>'],
            'preceding link' => ['[e](/u)x{.c}', '<p><a href="/u">e</a><span class="c">x</span></p>'],
            'preceding code' => ['`e`x{.c}', '<p><code>e</code><span class="c">x</span></p>'],
            'preceding math' => ['$`e`x{.c}', '<p><span class="math inline">\\(e\\)</span><span class="c">x</span></p>'],
            'attributes on emphasis' => ['x_e_{.c}', '<p>x<em class="c">e</em></p>'],
            'attributes on span' => ['x[e]{.c}', '<p>x<span class="c">e</span></p>'],
            'attributes on link' => ['x[e](/u){.c}', '<p>x<a href="/u" class="c">e</a></p>'],
            'attributes on code' => ['x`e`{.c}', '<p>x<code class="c">e</code></p>'],
            'attributes on math' => ['x$`e`{.c}', '<p>x<span class="math inline c">\\(e\\)</span></p>'],
            'empty word specifier' => ['word{}', '<p>word</p>'],
            'whitespace word specifier' => ['word{ }', '<p>word</p>'],
            'comment word specifier' => ['word{% comment %}', '<p>word</p>'],
            'comment boundary inside word' => ['a{% note %}b{.c}', '<p>a<span class="c">b</span></p>'],
            'comment before consecutive attributes' => ['a{% note %}{.c}', '<p><span class="c">a</span></p>'],
            'empty explicit span' => ['[text]{}', '<p><span>text</span></p>'],
            'several escapes' => ['a\\*b\\[c\\]{.c}', '<p><span class="c">a*b[c]</span></p>'],
            'escaped quote within word' => ['a\\"b{.c}', '<p><span class="c">a"b</span></p>'],
            'escape after emphasis' => ['_e_\\*x{.c}', '<p><em>e</em><span class="c">*x</span></p>'],
            'whitespace before escape' => ['foo \\*bar{.c}', '<p>foo <span class="c">*bar</span></p>'],
            'empty specifier inside word' => ['foo*{}bar{.c}', '<p>foo*<span class="c">bar</span></p>'],
            'consecutive word attributes' => ['foo*bar{.c}{#i}', '<p><span class="c" id="i">foo*bar</span></p>'],
        ];
        foreach (['space' => ' ', 'tab' => "\t", 'newline' => "\n", 'return' => "\r", 'vertical tab' => "\v", 'form feed' => "\f"] as $name => $space) {
            $renderedSpace = $space === "\r" ? "\n" : $space;
            $cases['word boundary at ' . $name] = [
                'foo*bar' . $space . 'baz{.c}',
                '<p>foo*bar' . $renderedSpace . '<span class="c">baz</span></p>',
            ];
            $cases['no word after ' . $name] = [
                'foo\\*' . $space . '{.c}x',
                '<p>foo*' . $renderedSpace . 'x</p>',
            ];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0:string, 1:string, 2?:bool}>
     */
    public static function parityProvider(): array
    {
        return [
            'forced-quotes repro 1' => [
                "a {\"b c\n",
                "<p>a “b c</p>\n",
            ],
            'forced-quotes repro 2' => [
                "'}Tis the season\n",
                "<p>’Tis the season</p>\n",
            ],
            'forced-quotes repro 3' => [
                "a \"}b c\n",
                "<p>a ”b c</p>\n",
            ],
            'attr-whitespace repro 4' => [
                "word{ .c }\n",
                "<p><span class=\"c\">word</span></p>\n",
            ],
            'attr-whitespace repro 5' => [
                "x{ }y\n",
                "<p>xy</p>\n",
            ],
            'attr-whitespace repro 6' => [
                "{ }\n*p*\n",
                "<p><strong>p</strong></p>\n",
            ],
            'math-attr repro 7' => [
                "\$`m`{#i}\n",
                "<p><span class=\"math inline\" id=\"i\">\\(m\\)</span></p>\n",
            ],
            'math-attr repro 8' => [
                "\$`m`{.c}\n",
                "<p><span class=\"math inline c\">\\(m\\)</span></p>\n",
            ],
            'escaped-attr repro 9' => [
                "\\*{#i}\n",
                "<p><span id=\"i\">*</span></p>\n",
            ],
            'comment-ends-at-brace repro 10' => [
                "a {% b} c %} d\n",
                "<p>a  c %} d</p>\n",
            ],
            'comment-ends-at-brace repro 11' => [
                "{% {} %}\n",
                "<p> %}</p>\n",
            ],
            'dest-backslash repro 12' => [
                "[x](a\\b)\n",
                "<p><a href=\"a\\b\">x</a></p>\n",
            ],
            'dl-after-block repro 13' => [
                "- a\n\n: term\n",
                "<ul>\n<li>\na\n</li>\n</ul>\n<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'dl-after-block repro 14' => [
                "> a\n\n: term\n",
                "<blockquote>\n<p>a</p>\n</blockquote>\n<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'dl-lazy repro 15' => [
                ": term\n\n  def\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>def\nplain</p>\n</dd>\n</dl>\n",
            ],
            'ref-printed-and-used repro 16' => [
                "[r][]\n\npara\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n[r]: /u</p>\n",
            ],
            'backslash-space-newline repro 17' => [
                "a\\ \nb\n",
                "<p>a<br>\nb</p>\n",
            ],
            'backslash-space-newline repro 18' => [
                "a\\  \nb\n",
                "<p>a<br>\nb</p>\n",
            ],
            'block-attr-blank-line repro 19' => [
                "{#id .class}\n\nA paragraph\n",
                "<p>A paragraph</p>\n",
            ],
            'fence-info-spaces repro 20' => [
                "``` not a code block\n",
                "<p><code> not a code block</code></p>\n",
            ],
            'emphasis-pairing repro 21' => [
                "*not strong *strong*\n",
                "<p>*not strong <strong>strong</strong></p>\n",
            ],
            'emphasis-pairing repro 22' => [
                "__emphasis inside_ emphasis_\n",
                "<p><em><em>emphasis inside</em> emphasis</em></p>\n",
            ],
            'br-trailing-space repro 23' => [
                "a \\\nb\n",
                "<p>a<br>\nb</p>\n",
            ],
            'forced single opener' => [
                "a {'b c\n",
                "<p>a ‘b c</p>\n",
            ],
            'forced quote pair' => [
                "a {\" b \"} c\n",
                "<p>a “ b ” c</p>\n",
            ],
            'forced single pair' => [
                "a {' b '} c\n",
                "<p>a ‘ b ’ c</p>\n",
            ],
            'forced quotes in destination' => [
                "[x](a\"}b)\n",
                "<p><a href=\"a&quot;}b\">x</a></p>\n",
            ],
            'forced quotes in code' => [
                "`{\"a\"} {'b'}`\n",
                "<p><code>{\"a\"} {'b'}</code></p>\n",
            ],
            'forced quotes in raw' => [
                "`{\"a\"} {'b'}`{=html}\n",
                "<p>{\"a\"} {'b'}</p>\n",
            ],
            'forced quotes in math' => [
                "\$`{\"a\"} {'b'}`\n",
                "<p><span class=\"math inline\">\\({\"a\"} {'b'}\\)</span></p>\n",
            ],
            'forced quotes in attributes' => [
                "word{title=\"{\\\"b\\\"}\"}\n",
                "<p><span title=\"{&quot;b&quot;}\">word</span></p>\n",
            ],
            'whitespace attribute tab' => [
                "word{\t.c\t}\n",
                "<p><span class=\"c\">word</span></p>\n",
            ],
            'whitespace empty attribute' => [
                "word{\t }end\n",
                "<p>wordend</p>\n",
            ],
            'whitespace block attribute' => [
                "{ .c }\np\n",
                "<p class=\"c\">p</p>\n",
            ],
            'whitespace multiline attribute' => [
                "word{\n .c\n}\n",
                "<p><span class=\"c\">word</span></p>\n",
            ],
            'math consecutive attributes' => [
                "\$`x`{#i}{.c}\n",
                "<p><span class=\"math inline c\" id=\"i\">\\(x\\)</span></p>\n",
            ],
            'display math attributes' => [
                "\$\$`x`{.c #i}\n",
                "<p><span class=\"math display c\" id=\"i\">\\[x\\]</span></p>\n",
            ],
            'escaped attributes after word' => [
                "word\\*{.c}{#i}next\n",
                "<p><span class=\"c\" id=\"i\">word*</span>next</p>\n",
            ],
            'escaped brace attributes' => [
                "\\{{.c}\n",
                "<p><span class=\"c\">{</span></p>\n",
            ],
            'escaped empty attributes' => [
                "\\*{ }\n",
                "<p>*</p>\n",
            ],
            'comment brace with quote' => [
                "a{% \"}b\n",
                "<p>ab</p>\n",
            ],
            'comment brace with escape' => [
                "a{% \\}b\n",
                "<p>ab</p>\n",
            ],
            'quoted attribute comment marker' => [
                "word{a=\"{%}\"}\n",
                "<p><span a=\"{%}\">word</span></p>\n",
            ],
            'comment quoted text then attributes' => [
                "word{% \"ignored\" % .c}\n",
                "<p><span class=\"c\">word</span></p>\n",
            ],
            'comment block attributes' => [
                "{% \"ignored\" % .c}\np\n",
                "<p class=\"c\">p</p>\n",
            ],
            'comment block closing brace' => [
                "{% a}\np\n",
                "<p>p</p>\n",
            ],
            'comment percent then attributes' => [
                "word{% ignored % .c}\n",
                "<p><span class=\"c\">word</span></p>\n",
            ],
            'comment nested braces' => [
                "a{% {{ }rest %}\n",
                "<p>arest %}</p>\n",
            ],
            'destination punctuation' => [
                "[x](a\\*b\\_c\\(d\\))\n",
                "<p><a href=\"a*b_c(d)\">x</a></p>\n",
            ],
            'destination unicode' => [
                "[x](a\\éb\\。c)\n",
                "<p><a href=\"a\\éb\\。c\">x</a></p>\n",
            ],
            'destination doubled backslash' => [
                "[x](a\\\\b)\n",
                "<p><a href=\"a\\b\">x</a></p>\n",
            ],
            'definition after heading' => [
                "# h\n\n: term\n",
                "<section id=\"h\">\n<h1>h</h1>\n<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n</section>\n",
            ],
            'definition after code' => [
                "```\nx\n```\n\n: term\n",
                "<pre><code>x\n</code></pre>\n<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n",
            ],
            'definition lazy multiple lines' => [
                ": term\n\n  first\nsecond\nthird\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first\nsecond\nthird</p>\n</dd>\n</dl>\n",
            ],
            'definition lazy stops at heading' => [
                ": term\n\n  first\n# heading\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n<section id=\"heading\">\n<h1>heading</h1>\n</section>\n",
            ],
            'definition lazy stops at attributes' => [
                ": term\n\n  first\n{#i}\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n",
            ],
            'definition lazy stops at reference' => [
                ": term\n\n  first\n[r]: /u\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n",
            ],
            'definition lazy after blank' => [
                ": term\n\n  first\n\nsecond\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n<p>second</p>\n",
            ],
            'definition lazy after code' => [
                ": term\n\n  ```\n  x\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'reference after paragraph marker' => [
                "[r][]\n\npara\n# literal heading\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n# literal heading\n[r]: /u</p>\n",
            ],
            'reference after heading' => [
                "# h\n[r]: /u\n\n[r][]\n",
                "<section id=\"h\">\n<h1>h</h1>\n<p><a href=\"/u\">r</a></p>\n</section>\n",
            ],
            'reference after heading continuation' => [
                "# h\ncontinued\n[r]: /u\n\n[r][]\n",
                "<section id=\"h-continued\">\n<h1>h\ncontinued</h1>\n<p><a href=\"/u\">r</a></p>\n</section>\n",
            ],
            'reference after quoted brace attributes' => [
                "[r][]\n\n{title=\"}\"}\n[r]: /u\n",
                "<p><a href=\"/u\" title=\"}\">r</a></p>\n",
            ],
            'reference after commented attributes' => [
                "[r][]\n\n{% ignored % .c}\n[r]: /u\n",
                "<p><a href=\"/u\" class=\"c\">r</a></p>\n",
            ],
            'reference after block comment' => [
                "[r][]\n\n{% ignored }\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n",
            ],
            'reference after literal div marker' => [
                "[r][]\n\npara\n::: \n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n::: \n[r]: /u</p>\n",
            ],
            'reference after thematic break' => [
                "[r][]\n\n***\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<hr>\n",
            ],
            'reference after table' => [
                "[r][]\n\n| t |\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<table>\n<tr>\n<td>t</td>\n</tr>\n</table>\n",
            ],
            'reference after div' => [
                "[r][]\n\n::: d\nin\n:::\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<div class=\"d\">\n<p>in</p>\n</div>\n",
            ],
            'reference at block boundary' => [
                "[r][]\n\npara\n\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n",
            ],
            'reference consecutive' => [
                "[a][] [b][]\n\n[a]: /a\n[b]: /b\n",
                "<p><a href=\"/a\">a</a> <a href=\"/b\">b</a></p>\n",
            ],
            'reference in fence' => [
                "[r][]\n\n```\n[r]: /u\n```\n",
                "<p><a>r</a></p>\n<pre><code>[r]: /u\n</code></pre>\n",
            ],
            'block attributes adjacent' => [
                "{#i}\np\n",
                "<p id=\"i\">p</p>\n",
            ],
            'block attributes multiple blank lines' => [
                "{#i}\n\n\np\n",
                "<p>p</p>\n",
            ],
            'block attributes reset then adjacent' => [
                "{#old}\n\n{#new}\np\n",
                "<p id=\"new\">p</p>\n",
            ],
            'fence invalid in quote with lazy continuation' => [
                "> ``` not code\nplain\n",
                "<blockquote>\n<p><code> not code\nplain</code></p>\n</blockquote>\n",
            ],
            'fence invalid in definition with lazy continuation' => [
                ": term\n\n  ``` not code\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p><code> not code\nplain</code></p>\n</dd>\n</dl>\n",
            ],
            'fence spaces with content' => [
                "``` not code\nx\n```\n",
                "<p><code> not code\nx\n</code></p>\n",
            ],
            'fence tilde spaces' => [
                "~~~ not code\n",
                "<p>~~~ not code</p>\n",
            ],
            'fence single language' => [
                "``` php\nx\n```\n",
                "<pre><code class=\"language-php\">x\n</code></pre>\n",
            ],
            'emphasis closer picks latest opener' => [
                "_not emph _emph_\n",
                "<p>_not emph <em>emph</em></p>\n",
            ],
            'emphasis nested strong' => [
                "**strong inside* strong*\n",
                "<p><strong><strong>strong inside</strong> strong</strong></p>\n",
            ],
            'emphasis nested runs' => [
                "___nested___\n",
                "<p><em><em><em>nested</em></em></em></p>\n",
            ],
            'emphasis symmetric runs' => [
                "__nested__\n",
                "<p><em><em>nested</em></em></p>\n",
            ],
            'emphasis escapes' => [
                "*not \\*nested*\n",
                "<p><strong>not *nested</strong></p>\n",
            ],
            'definition table ""' => [
                ": term\n\n  | a |\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition table "first\\n\\n"' => [
                ": term\n\n  first\n\n  | a |\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition separator ""' => [
                ": term\n\n  |---|\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<table>\n</table>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition separator "first\\n\\n"' => [
                ": term\n\n  first\n\n  |---|\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<table>\n</table>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition heading ""' => [
                ": term\n\n  # h\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<h1 id=\"h-plain\">h\nplain</h1>\n</dd>\n</dl>\n",
            ],
            'definition heading "first\\n\\n"' => [
                ": term\n\n  first\n\n  # h\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<h1 id=\"h-plain\">h\nplain</h1>\n</dd>\n</dl>\n",
            ],
            'definition emptyHeading ""' => [
                ": term\n\n  #\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<h1 id=\"plain\">plain</h1>\n</dd>\n</dl>\n",
            ],
            'definition emptyHeading "first\\n\\n"' => [
                ": term\n\n  first\n\n  #\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<h1 id=\"plain\">plain</h1>\n</dd>\n</dl>\n",
            ],
            'definition break ""' => [
                ": term\n\n  ***\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<hr>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition break "first\\n\\n"' => [
                ": term\n\n  first\n\n  ***\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<hr>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition dashBreak ""' => [
                ": term\n\n  ---\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<hr>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition dashBreak "first\\n\\n"' => [
                ": term\n\n  first\n\n  ---\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<hr>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition quote ""' => [
                ": term\n\n  > a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<blockquote>\n<p>a\nplain</p>\n</blockquote>\n</dd>\n</dl>\n",
            ],
            'definition quote "first\\n\\n"' => [
                ": term\n\n  first\n\n  > a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<blockquote>\n<p>a\nplain</p>\n</blockquote>\n</dd>\n</dl>\n",
            ],
            'definition emptyQuote ""' => [
                ": term\n\n  >\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<blockquote>\n</blockquote>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition emptyQuote "first\\n\\n"' => [
                ": term\n\n  first\n\n  >\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<blockquote>\n</blockquote>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition list ""' => [
                ": term\n\n  - a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<ul>\n<li>\na\nplain\n</li>\n</ul>\n</dd>\n</dl>\n",
            ],
            'definition list "first\\n\\n"' => [
                ": term\n\n  first\n\n  - a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<ul>\n<li>\na\nplain\n</li>\n</ul>\n</dd>\n</dl>\n",
            ],
            'definition emptyList ""' => [
                ": term\n\n  -\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<ul>\n<li>\n</li>\n</ul>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition emptyList "first\\n\\n"' => [
                ": term\n\n  first\n\n  -\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<ul>\n<li>\n</li>\n</ul>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition ordered ""' => [
                ": term\n\n  1. a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<ol>\n<li>\na\nplain\n</li>\n</ol>\n</dd>\n</dl>\n",
            ],
            'definition ordered "first\\n\\n"' => [
                ": term\n\n  first\n\n  1. a\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<ol>\n<li>\na\nplain\n</li>\n</ol>\n</dd>\n</dl>\n",
            ],
            'definition code ""' => [
                ": term\n\n  ```\n  x\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition code "first\\n\\n"' => [
                ": term\n\n  first\n\n  ```\n  x\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition raw ""' => [
                ": term\n\n  ``` =html\n  <b>x</b>\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<b>x</b>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition raw "first\\n\\n"' => [
                ": term\n\n  first\n\n  ``` =html\n  <b>x</b>\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<b>x</b>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition div ""' => [
                ": term\n\n  ::: c\n  in\n  :::\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<div class=\"c\">\n<p>in</p>\n</div>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition div "first\\n\\n"' => [
                ": term\n\n  first\n\n  ::: c\n  in\n  :::\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n<div class=\"c\">\n<p>in</p>\n</div>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition reference ""' => [
                ": term\n\n  [r]: /u\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition reference "first\\n\\n"' => [
                ": term\n\n  first\n\n  [r]: /u\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition comment ""' => [
                ": term\n\n  {% ignored }\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition comment "first\\n\\n"' => [
                ": term\n\n  first\n\n  {% ignored }\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<p>first</p>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'star' => [
                "[r][]\n\npara\n***\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<hr>\n",
                true,
            ],
            'star default' => [
                "[r][]\n\npara\n***\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n***\n[r]: /u</p>\n",
            ],
            'dash' => [
                "[r][]\n\npara\n---\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<hr>\n",
                true,
            ],
            'dash default' => [
                "[r][]\n\npara\n---\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n—\n[r]: /u</p>\n",
            ],
            'spaced' => [
                "[r][]\n\npara\n* * *\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<hr>\n",
                true,
            ],
            'spaced default' => [
                "[r][]\n\npara\n* * *\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n* * *\n[r]: /u</p>\n",
            ],
            'heading' => [
                "[r][]\n\npara\n# h\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<section id=\"h\">\n<h1>h</h1>\n</section>\n",
                true,
            ],
            'heading default' => [
                "[r][]\n\npara\n# h\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n# h\n[r]: /u</p>\n",
            ],
            'table' => [
                "[r][]\n\npara\n| a |\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n",
                true,
            ],
            'table default' => [
                "[r][]\n\npara\n| a |\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n| a |\n[r]: /u</p>\n",
            ],
            'separator' => [
                "[r][]\n\npara\n|---|\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<table>\n</table>\n",
                true,
            ],
            'separator default' => [
                "[r][]\n\npara\n|---|\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n|—|\n[r]: /u</p>\n",
            ],
            'code' => [
                "[r][]\n\npara\n```\nx\n```\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<pre><code>x\n</code></pre>\n",
                true,
            ],
            'code default' => [
                "[r][]\n\npara\n```\nx\n```\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n<code>\nx\n</code>\n[r]: /u</p>\n",
            ],
            'raw' => [
                "[r][]\n\npara\n``` =html\n<b>x</b>\n```\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<b>x</b>\n",
                true,
            ],
            'raw default' => [
                "[r][]\n\npara\n``` =html\n<b>x</b>\n```\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n<code> =html\n&lt;b&gt;x&lt;/b&gt;\n</code>\n[r]: /u</p>\n",
            ],
            'div' => [
                "[r][]\n\npara\n::: c\nin\n:::\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<div class=\"c\">\n<p>in</p>\n</div>\n",
                true,
            ],
            'div default' => [
                "[r][]\n\npara\n::: c\nin\n:::\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n::: c\nin\n:::\n[r]: /u</p>\n",
            ],
            'quote default' => [
                "[r][]\n\npara\n> a\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n&gt; a\n[r]: /u</p>\n",
            ],
            'list default' => [
                "[r][]\n\npara\n- a\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n- a\n[r]: /u</p>\n",
            ],
            'ordered default' => [
                "[r][]\n\npara\n1. a\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n1. a\n[r]: /u</p>\n",
            ],
            'emptyQuote' => [
                "[r][]\n\npara\n>\n[r]: /u\n",
                "<p><a href=\"/u\">r</a></p>\n<p>para</p>\n<blockquote>\n</blockquote>\n",
                true,
            ],
            'emptyList default' => [
                "[r][]\n\npara\n- \n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n- \n[r]: /u</p>\n",
            ],
            'emptyOrdered default' => [
                "[r][]\n\npara\n1. \n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n1. \n[r]: /u</p>\n",
            ],
            'emphasis opener before space' => [
                "__ a_\n",
                "<p><em>_ a</em></p>\n",
            ],
            'strong opener before space' => [
                "** a*\n",
                "<p><strong>* a</strong></p>\n",
            ],
            'reference paragraph remains unresolved with interruption enabled' => [
                "[r][]\n\npara\n[r]: /u\n",
                "<p><a>r</a></p>\n<p>para\n[r]: /u</p>\n",
                true,
            ],
            'definition attributes retain dd extension' => [
                ": term\n\n  {.c}\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd class=\"c\">\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'definition fenced comment retains extension' => [
                ": term\n\n  %%%\n  comment\n  %%%\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'quoted definition fence then paragraph' => [
                ": term\n\n  > ```\n  > x\n  > ```\n  next\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<blockquote>\n<pre><code>x\n</code></pre>\n</blockquote>\n<p>next\nplain</p>\n</dd>\n</dl>\n",
            ],
            'quoted definition fence ends lazy continuation' => [
                ": term\n\n  > ```\n  > x\n  > ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<blockquote>\n<pre><code>x\n</code></pre>\n</blockquote>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'quoted marker inside definition fence' => [
                ": term\n\n  ```\n  > ```\n  x\n  ```\nplain\n",
                "<dl>\n<dt>term</dt>\n<dd>\n<pre><code>&gt; ```\nx\n</code></pre>\n</dd>\n</dl>\n<p>plain</p>\n",
            ],
            'break multiple spaces' => [
                "a   \\\nb\n",
                "<p>a<br>\nb</p>\n",
            ],
            'break tab' => [
                "a\t\\\nb\n",
                "<p>a<br>\nb</p>\n",
            ],
            'break after inline' => [
                "*a*  \\\nb\n",
                "<p><strong>a</strong><br>\nb</p>\n",
            ],
            'break at paragraph end' => [
                "a  \\\n",
                "<p>a<br>\n</p>\n",
            ],
            'break surrounding spaces' => [
                "a  \\  \nb\n",
                "<p>a<br>\nb</p>\n",
            ],
        ];
    }
}
