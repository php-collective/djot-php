<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blockquote's lazy continuation (a line without the ">" marker) may only extend
 * an OPEN paragraph, per the djot/CommonMark rule. Previously a non-">" line was
 * swallowed into an open fenced code block or just-opened div inside the quote,
 * corrupting content and stripping ">" markers off following lines. These cases
 * are verified against the canonical djot.js reference output.
 */
class BlockQuoteLazyContinuationTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testNonMarkerLineInsideOpenFenceTerminatesQuote(): void
    {
        // The non-">" lines must NOT be swallowed into the code block; the quote
        // ends and they become a separate paragraph (">" rendered literally).
        $djot = "> ```\n> a\nb\n> c";
        $expected = "<blockquote>\n<pre><code>a\n</code></pre>\n</blockquote>\n"
            . "<p>b\n&gt; c</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testNonMarkerLineAfterClosedFenceTerminatesQuote(): void
    {
        // Fence is closed; a following non-">" line has no open paragraph to
        // continue, so it leaves the quote.
        $djot = "> ```\n> code\n> ```\ntail";
        $expected = "<blockquote>\n<pre><code>code\n</code></pre>\n</blockquote>\n"
            . "<p>tail</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testNonMarkerLineAfterDivOpenerTerminatesQuote(): void
    {
        // The div just opened (no paragraph yet), so a non-">" line ends the quote
        // and is not pulled inside the div.
        $djot = "> :::note\nbody\n> :::";
        $expected = "<blockquote>\n<div class=\"note\">\n</div>\n</blockquote>\n"
            . "<p>body\n&gt; :::</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testNonMarkerLineWhileFenceOpenTerminatesQuote(): void
    {
        $djot = "> ```\n> code\nlazy";
        $expected = "<blockquote>\n<pre><code>code\n</code></pre>\n</blockquote>\n"
            . "<p>lazy</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyContinuationOfParagraphStillFolds(): void
    {
        // Regression guard: a non-">" line continuing an OPEN paragraph still folds
        // into the blockquote (unchanged correct behavior).
        $djot = "> p\nlazy\n> more";
        $expected = "<blockquote>\n<p>p\nlazy\nmore</p>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyContinuationOfParagraphInsideDivStillFolds(): void
    {
        // Regression guard: a paragraph IS open inside the div, so the lazy line
        // folds into it (must not be broken by the fix).
        $djot = "> :::note\n> para\nlazy\n> :::";
        $expected = "<blockquote>\n<div class=\"note\">\n<p>para\nlazy</p>\n</div>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyContinuationOfListItemParagraphStillFolds(): void
    {
        // Regression guard: lazy line continues the list item's paragraph.
        $djot = "> - a\nlazy\n> - b";
        $expected = "<blockquote>\n<ul>\n<li>\na\nlazy\n</li>\n<li>\nb\n</li>\n</ul>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testFenceLikeLineInsideOpenParagraphStaysParagraphText(): void
    {
        // Regression guard: a code fence does NOT interrupt an open paragraph in
        // strict djot, so the fence-looking line is paragraph text and the next
        // unquoted line keeps lazily continuing the paragraph.
        $djot = "> text\n> ```\nlazy";
        $expected = "<blockquote>\n<p>text\n<code>\nlazy</code></p>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * A block closed inside a quoted list item leaves no paragraph for an
     * unquoted line to continue, so the line ends the quote (matches djot.js).
     */
    #[DataProvider('closedBlockInQuotedListItemProvider')]
    public function testUnquotedLineAfterClosedBlockInListItemTerminatesQuote(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function closedBlockInQuotedListItemProvider(): array
    {
        return [
            'sibling marker after closed div' => [
                "> - ::: box\n>   :::\n- tail",
                "<blockquote>\n<ul>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'indented marker after closed div' => [
                "> - ::: box\n>   :::\n  - tail",
                "<blockquote>\n<ul>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'plain line after closed div' => [
                "> - ::: box\n>   :::\ntail",
                "<blockquote>\n<ul>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ul>\n</blockquote>\n<p>tail</p>\n",
            ],
            'closed div with content' => [
                "> - ::: box\n>   x\n>   :::\n- tail",
                "<blockquote>\n<ul>\n<li>\n<div class=\"box\">\n<p>x</p>\n</div>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'closed code fence' => [
                "> - ```\n>   ```\n- tail",
                "<blockquote>\n<ul>\n<li>\n<pre><code></code></pre>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'ordered item' => [
                "> 1. ::: box\n>    :::\n2. tail",
                "<blockquote>\n<ol>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ol>\n</blockquote>\n<ol start=\"2\">\n<li>\ntail\n</li>\n</ol>\n",
            ],
            'nested list item' => [
                "> - - ::: box\n>     :::\n- tail",
                "<blockquote>\n<ul>\n<li>\n<ul>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ul>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'quote inside item' => [
                "> - > ::: box\n>   > :::\n- tail",
                "<blockquote>\n<ul>\n<li>\n<blockquote>\n<div class=\"box\">\n</div>\n</blockquote>\n</li>\n</ul>\n</blockquote>\n<ul>\n<li>\ntail\n</li>\n</ul>\n",
            ],
            'list inside a closed div' => [
                "> ::: note\n> - x\n> :::\ntail",
                "<blockquote>\n<div class=\"note\">\n<ul>\n<li>\nx\n</li>\n</ul>\n</div>\n</blockquote>\n<p>tail</p>\n",
            ],
        ];
    }

    /**
     * A fence or div opened in a quoted list item ends with the item, so a later
     * quoted paragraph still takes an unquoted continuation line.
     */
    #[DataProvider('blockEndingWithQuotedListItemProvider')]
    public function testParagraphAfterListItemStillFolds(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockEndingWithQuotedListItemProvider(): array
    {
        return [
            'div ends with its list item' => [
                "> - ::: box\n> text\n> :::\ntail",
                "<blockquote>\n<ul>\n<li>\n<div class=\"box\">\n</div>\n</li>\n</ul>\n<p>text\n:::\ntail</p>\n</blockquote>\n",
            ],
            'code fence ends with its list item' => [
                "> - ```\n> text\ntail",
                "<blockquote>\n<ul>\n<li>\n<pre><code></code></pre>\n</li>\n</ul>\n<p>text\ntail</p>\n</blockquote>\n",
            ],
            'nested quote fence ends with its list item' => [
                "> - > ```\n> > x\ntail",
                "<blockquote>\n<ul>\n<li>\n<blockquote>\n<pre><code></code></pre>\n</blockquote>\n</li>\n</ul>\n<blockquote>\n<p>x\ntail</p>\n</blockquote>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('nestedContainerEdgeCaseProvider')]
    public function testNestedContainerEdgeCases(string $djot, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedContainerEdgeCaseProvider(): array
    {
        return [
            'blank line closes nested quote' => [
                "> > x\n>\ntail",
                "<blockquote>\n<blockquote>\n<p>x</p>\n</blockquote>\n</blockquote>\n<p>tail</p>\n",
            ],
            'indented comment marker is paragraph text' => [
                "> x\n>   %%%\ntail",
                "<blockquote>\n<p>x\n%%%\ntail</p>\n</blockquote>\n",
            ],
            'comment marker in item lead paragraph is text' => [
                "> - x\n>   %%%\ntail",
                "<blockquote>\n<ul>\n<li>\nx\n%%%\ntail\n</li>\n</ul>\n</blockquote>\n",
            ],
            'indented comment marker after nested quote is text' => [
                "> > x\n>   %%%\ntail",
                "<blockquote>\n<blockquote>\n<p>x\n%%%\ntail</p>\n</blockquote>\n</blockquote>\n",
            ],
            'closer inside nested quote leaves outer div open' => [
                "> :::\n> > x\n> > :::\ntail",
                "<blockquote>\n<div>\n<blockquote>\n<p>x\n:::\ntail</p>\n</blockquote>\n</div>\n</blockquote>\n",
            ],
            'fence marker continuing a reference definition' => [
                "> [a]: /url\n>   ```\n> x\ntail",
                "<blockquote>\n<p>x\ntail</p>\n</blockquote>\n",
            ],
            'bare nested quote holds no paragraph' => [
                "> >\ntail",
                "<blockquote>\n<blockquote>\n</blockquote>\n</blockquote>\n<p>tail</p>\n",
            ],
            'empty list item holds no paragraph' => [
                "> -\ntail",
                "<blockquote>\n<ul>\n<li>\n</li>\n</ul>\n</blockquote>\n<p>tail</p>\n",
            ],
            'table row in item holds no paragraph' => [
                "> - | a |\ntail",
                "<blockquote>\n<ul>\n<li>\n<table>\n<tr>\n<td>a</td>\n</tr>\n</table>\n</li>\n</ul>\n</blockquote>\n<p>tail</p>\n",
            ],
            'attribute line applies to the unquoted line' => [
                "> {.c}\ntail",
                "<blockquote>\n</blockquote>\n<p class=\"c\">tail</p>\n",
            ],
            'attribute line in item applies to the unquoted line' => [
                "> - {.c}\ntail",
                "<blockquote>\n<ul>\n<li>\n</li>\n</ul>\n</blockquote>\n<p class=\"c\">tail</p>\n",
            ],
        ];
    }
}
