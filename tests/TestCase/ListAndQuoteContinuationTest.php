<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List item and block quote continuation, matching djot.js.
 */
class ListAndQuoteContinuationTest extends TestCase
{
    /**
     * A bullet item is an item whatever character its content ends with.
     */
    #[DataProvider('itemEndingInBulletProvider')]
    public function testItemEndingInBullet(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function itemEndingInBulletProvider(): array
    {
        return [
            'item ending in its bullet' => [
                '- a -',
                "<ul>\n<li>\na -\n</li>\n</ul>\n",
            ],
            'star item ending in a star' => [
                '* note *',
                "<ul>\n<li>\nnote *\n</li>\n</ul>\n",
            ],
            'item ending in an en dash' => [
                '- wait --',
                "<ul>\n<li>\nwait –\n</li>\n</ul>\n",
            ],
            'later item ending in its bullet' => [
                "- a\n- b -",
                "<ul>\n<li>\na\n</li>\n<li>\nb -\n</li>\n</ul>\n",
            ],
            'ordered item ending in a dash' => [
                '1. a -',
                "<ol>\n<li>\na -\n</li>\n</ol>\n",
            ],
            'item glued to its bullet' => [
                '- a-',
                "<ul>\n<li>\na-\n</li>\n</ul>\n",
            ],
        ];
    }

    /**
     * An unindented line that opens no block continues the open paragraph of a list item, also after a blank line or in a nested item.
     */
    #[DataProvider('lazyLineInLaterParagraphProvider')]
    public function testLazyLineInLaterParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lazyLineInLaterParagraphProvider(): array
    {
        return [
            'lazy quote-like line after a later paragraph' => [
                "- one\n\n  two\n>three",
                "<ul>\n<li>\n<p>one</p>\n<p>two\n&gt;three</p>\n</li>\n</ul>\n",
            ],
            'lazy star run after a later paragraph' => [
                "- one\n\n  two\n***three",
                "<ul>\n<li>\n<p>one</p>\n<p>two\n***three</p>\n</li>\n</ul>\n",
            ],
            'lazy line in a nested item' => [
                "- - a\n\n    h\nlazy",
                "<ul>\n<li>\n<ul>\n<li>\n<p>a</p>\n<p>h\nlazy</p>\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'lazy line in an ordered item' => [
                "1. one\n\n   two\n>three",
                "<ol>\n<li>\n<p>one</p>\n<p>two\n&gt;three</p>\n</li>\n</ol>\n",
            ],
            'real block quote still ends the list' => [
                "- one\n\n  two\n> three",
                "<ul>\n<li>\n<p>one</p>\n<p>two</p>\n</li>\n</ul>\n<blockquote>\n<p>three</p>\n</blockquote>\n",
            ],
            'thematic break still ends the list' => [
                "- one\n\n  two\n***",
                "<ul>\n<li>\n<p>one</p>\n<p>two</p>\n</li>\n</ul>\n<hr>\n",
            ],
        ];
    }

    /**
     * An indented marker under an empty list item starts a sublist at any indent past the outer marker.
     */
    #[DataProvider('indentedMarkerUnderEmptyItemProvider')]
    public function testIndentedMarkerUnderEmptyItem(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function indentedMarkerUnderEmptyItemProvider(): array
    {
        return [
            'three spaces' => [
                "-\n   - b",
                "<ul>\n<li>\n<ul>\n<li>\nb\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'four spaces' => [
                "-\n    - b",
                "<ul>\n<li>\n<ul>\n<li>\nb\n</li>\n</ul>\n</li>\n</ul>\n",
            ],
            'ordered child marker' => [
                "-\n   5. b",
                "<ul>\n<li>\n<ol start=\"5\">\n<li>\nb\n</li>\n</ol>\n</li>\n</ul>\n",
            ],
            'inside a block quote' => [
                "> -\n>    - b",
                "<blockquote>\n<ul>\n<li>\n<ul>\n<li>\nb\n</li>\n</ul>\n</li>\n</ul>\n</blockquote>\n",
            ],
            'ordered parent' => [
                "1.\n    - b",
                "<ol>\n<li>\n<ul>\n<li>\nb\n</li>\n</ul>\n</li>\n</ol>\n",
            ],
            'heading child' => [
                "-\n   # b",
                "<ul>\n<li>\n<h1 id=\"b\">b</h1>\n</li>\n</ul>\n",
            ],
        ];
    }

    /**
     * An unquoted line with no open paragraph to continue closes every open block quote.
     */
    #[DataProvider('unquotedLineAfterClosedQuoteParagraphProvider')]
    public function testUnquotedLineAfterClosedQuoteParagraph(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unquotedLineAfterClosedQuoteParagraphProvider(): array
    {
        return [
            'blank quote line closes the nested paragraph' => [
                "> > a\nb\n> >\nc",
                "<blockquote>\n<blockquote>\n<p>a\nb</p>\n</blockquote>\n</blockquote>\n<p>c</p>\n",
            ],
            'div closes the nested paragraph' => [
                "> > a\n> :::\nb",
                "<blockquote>\n<blockquote>\n<p>a</p>\n</blockquote>\n<div>\n</div>\n</blockquote>\n<p>b</p>\n",
            ],
            'lazy line keeps both quotes' => [
                "> > a\nb\nc",
                "<blockquote>\n<blockquote>\n<p>a\nb\nc</p>\n</blockquote>\n</blockquote>\n",
            ],
            'three levels' => [
                "> > > a\nb\n> > >\nc",
                "<blockquote>\n<blockquote>\n<blockquote>\n<p>a\nb</p>\n</blockquote>\n</blockquote>\n</blockquote>\n<p>c</p>\n",
            ],
            'heading closes the nested paragraph' => [
                "> > a\n> # h\nc",
                "<blockquote>\n<blockquote>\n<p>a</p>\n</blockquote>\n<h1 id=\"h-c\">h\nc</h1>\n</blockquote>\n",
            ],
        ];
    }
}
