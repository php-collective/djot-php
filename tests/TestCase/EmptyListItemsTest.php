<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for empty list items produced by a bare list marker.
 *
 * In canonical djot a list marker may be followed by a space *or a newline*,
 * so a marker alone on its own line (no content after it) is a valid empty
 * list item. This is confirmed by the reference implementation (djot.js):
 * `-`, `*`, `+`, `1.`, `(1)`, `a.`, `i.` each render as a single empty `<li>`.
 *
 * Before this fix djot-php required at least one space plus content, so a bare
 * marker fell through to a paragraph (`<p>-</p>`) or was swallowed as lazy
 * continuation text inside the previous item - a divergence from the reference.
 *
 * Definition lists (`:` marker) are deliberately out of scope here; they are
 * parsed by a separate path (tryParseDjotDefinitionList) and tracked separately.
 *
 * Expected HTML in each case mirrors djot.js output verbatim.
 */
class EmptyListItemsTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Lone bullet markers ====================

    public function testLoneHyphenIsEmptyBulletItem(): void
    {
        $result = $this->converter->convert("-\n");

        $this->assertSame("<ul>\n<li>\n</li>\n</ul>\n", $result);
    }

    public function testLoneAsteriskIsEmptyBulletItem(): void
    {
        $result = $this->converter->convert("*\n");

        $this->assertSame("<ul>\n<li>\n</li>\n</ul>\n", $result);
    }

    public function testLonePlusIsEmptyBulletItem(): void
    {
        $result = $this->converter->convert("+\n");

        $this->assertSame("<ul>\n<li>\n</li>\n</ul>\n", $result);
    }

    public function testTwoLoneMarkersAreTwoEmptyItems(): void
    {
        $result = $this->converter->convert("-\n-\n");

        $this->assertSame("<ul>\n<li>\n</li>\n<li>\n</li>\n</ul>\n", $result);
    }

    // ==================== Empty item between filled items ====================

    public function testEmptyBulletItemBetweenItems(): void
    {
        $result = $this->converter->convert("- a\n-\n- c\n");

        $this->assertSame(
            "<ul>\n<li>\na\n</li>\n<li>\n</li>\n<li>\nc\n</li>\n</ul>\n",
            $result,
        );
    }

    public function testMarkerWithTrailingSpaceIsEmptyItem(): void
    {
        $result = $this->converter->convert("- a\n- \n- c\n");

        $this->assertSame(
            "<ul>\n<li>\na\n</li>\n<li>\n</li>\n<li>\nc\n</li>\n</ul>\n",
            $result,
        );
    }

    // ==================== Ordered markers ====================

    public function testLoneOrderedMarkerIsEmptyItem(): void
    {
        $result = $this->converter->convert("1.\n");

        $this->assertSame("<ol>\n<li>\n</li>\n</ol>\n", $result);
    }

    public function testEmptyOrderedItemBetweenItems(): void
    {
        $result = $this->converter->convert("1. a\n2.\n3. c\n");

        $this->assertSame(
            "<ol>\n<li>\na\n</li>\n<li>\n</li>\n<li>\nc\n</li>\n</ol>\n",
            $result,
        );
    }

    public function testLoneParenthesizedOrderedMarkerIsEmptyItem(): void
    {
        $result = $this->converter->convert("(1)\n");

        $this->assertSame("<ol>\n<li>\n</li>\n</ol>\n", $result);
    }

    public function testLoneAlphaMarkerIsEmptyItem(): void
    {
        $result = $this->converter->convert("a.\n");

        $this->assertSame("<ol type=\"a\">\n<li>\n</li>\n</ol>\n", $result);
    }

    public function testLoneRomanMarkerIsEmptyItem(): void
    {
        $result = $this->converter->convert("i.\n");

        $this->assertSame("<ol type=\"i\">\n<li>\n</li>\n</ol>\n", $result);
    }

    // ==================== Non-collisions (unchanged behavior) ====================

    public function testThematicBreakIsNotAnEmptyItem(): void
    {
        $result = $this->converter->convert("---\n");

        $this->assertStringNotContainsString('<ul>', $result);
        $this->assertStringContainsString('<hr', $result);
    }

    public function testMarkerWithoutSpaceAndContentIsNotAList(): void
    {
        // No space between marker and content: stays a paragraph (matches djot.js).
        $result = $this->converter->convert("-x\n");

        $this->assertSame("<p>-x</p>\n", $result);
    }

    public function testLoneMarkerThenParagraph(): void
    {
        $result = $this->converter->convert("-\n\nfoo\n");

        $this->assertSame("<ul>\n<li>\n</li>\n</ul>\n<p>foo</p>\n", $result);
    }

    public function testBareMarkerAdoptsIndentedContinuation(): void
    {
        // A marker with no same-line content but an indented next line owns that
        // line as its content (matches djot.js): no stray blank line in the item.
        $result = $this->converter->convert("-\n  x\n");

        $this->assertSame("<ul>\n<li>\nx\n</li>\n</ul>\n", $result);
    }

    public function testEmptyItemDoesNotSwallowFollowingParagraph(): void
    {
        // An empty item has no open paragraph, so a non-indented following line
        // starts a new paragraph outside the list (matches djot.js); it must not
        // be pulled in as lazy continuation.
        $result = $this->converter->convert("-\nfoo\n");

        $this->assertSame("<ul>\n<li>\n</li>\n</ul>\n<p>foo</p>\n", $result);
    }

    public function testEmptyOrderedItemDoesNotSwallowFollowingParagraph(): void
    {
        $result = $this->converter->convert("1.\nfoo\n");

        $this->assertSame("<ol>\n<li>\n</li>\n</ol>\n<p>foo</p>\n", $result);
    }
}
