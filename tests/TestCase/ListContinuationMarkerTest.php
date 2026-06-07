<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * List continuation marker (AsciiDoc-style `+`).
 *
 * A lone `+` at the list marker column attaches the following block to the
 * current item with no blank line, keeping the list tight, without indenting the
 * block body. A bare `+` is never a bullet (a bullet needs `+ ` + content), so
 * it does not collide with `+`-bulleted lists; outside a list it stays literal.
 *
 * djot-php addition (not canonical djot).
 */
class ListContinuationMarkerTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->converter->getHtmlRenderer()->setSoftBreakMode(SoftBreakMode::Newline);
    }

    public function testAttachesCodeBlockFlushLeftAndTight(): void
    {
        $html = $this->converter->convert("- Build\n+\n```sh\ndocker build .\n```\n- Push");
        $this->assertStringContainsString("<li>\nBuild\n<pre><code class=\"language-sh\">docker build .\n</code></pre>", $html);
        $this->assertStringNotContainsString('<p>Build</p>', $html);
        $this->assertStringContainsString("<li>\nPush\n</li>", $html);
    }

    public function testAttachesBlockquoteTight(): void
    {
        $html = $this->converter->convert("- item\n+\n> note\n- next");
        $this->assertStringContainsString("<li>\nitem\n<blockquote>", $html);
        $this->assertStringNotContainsString('<p>item</p>', $html);
    }

    public function testBareMarkerIsNotABulletInsideOrOutsideList(): void
    {
        // Outside a list: a lone `+` is ordinary paragraph text.
        $this->assertStringContainsString('<p>+</p>', $this->converter->convert("para\n\n+\n\nnext"));
        // Real `+` bullets (marker + space + content) are unaffected.
        $bullets = $this->converter->convert("+ one\n+ two");
        $this->assertStringContainsString("<li>\none\n</li>", $bullets);
        $this->assertStringContainsString("<li>\ntwo\n</li>", $bullets);
    }

    public function testContinuationDoesNotLoosenList(): void
    {
        $html = $this->converter->convert("- a\n+\n> q\n- b");
        // No item is <p>-wrapped: the list stayed tight.
        $this->assertStringNotContainsString("<li>\n<p>", $html);
    }

    public function testAttachesTableAndDiv(): void
    {
        $table = $this->converter->convert("- item\n+\n| a | b |\n- next");
        $this->assertStringContainsString("<li>\nitem\n<table>", $table);

        $div = $this->converter->convert("- item\n+\n::: note\nhi\n:::\n- next");
        $this->assertStringContainsString("<li>\nitem\n<div class=\"note\">", $div);
    }

    /**
     * Strict scope: a `+` only attaches container/verbatim blocks. A leaf block
     * (heading, thematic break, plain paragraph) is not attached; the `+` stays
     * literal continuation text on the item.
     */
    public function testDoesNotAttachLeafBlocks(): void
    {
        foreach (['## Heading', '---', 'plain paragraph'] as $leaf) {
            $html = $this->converter->convert("- item\n+\n{$leaf}\n- next");
            $this->assertStringContainsString("item\n+\n", $html, "+ should stay literal before: {$leaf}");
            $this->assertStringNotContainsString('<blockquote>', $html);
        }
    }

    /**
     * Only the tight `x` / `+` / `y` form is a continuation marker; a blank line
     * before or after the `+` leaves it as ordinary text.
     */
    public function testBlankLineAroundMarkerIsNotContinuation(): void
    {
        $blankAfter = $this->converter->convert("- item\n+\n\n> note");
        $this->assertStringNotContainsString("<li>\nitem\n<blockquote>", $blankAfter);

        $blankBefore = $this->converter->convert("- item\n\n+\n> note");
        $this->assertStringNotContainsString("<li>\nitem\n<blockquote>", $blankBefore);
    }

    public function testTrailingMarkerWithNoFollowingBlockIsLiteral(): void
    {
        // A `+` with nothing after it is not a continuation marker.
        $html = $this->converter->convert("- item\n+");
        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertStringContainsString('+', $html);
    }

    public function testIndentedBlockAfterMarkerIsNotFlushAttachment(): void
    {
        // The attached block must sit flush at the marker column; an indented
        // block after `+` is not a tight continuation, so the `+` stays literal.
        $html = $this->converter->convert("- item\n+\n  > note\n- next");
        $this->assertStringContainsString("item\n+\n", $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
    }
}
