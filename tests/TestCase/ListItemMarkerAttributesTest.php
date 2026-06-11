<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for attributes attached directly to a list marker.
 *
 * Per the djot syntax proposal (jgm/djot#262), attributes in curly braces that
 * immediately follow a list marker - with no intervening space - attach to the
 * `<li>` element:
 *
 *     +{.blue} A blue list item.
 *     (a){.bar} Ordered list item with an attribute.
 *
 * A space between the marker and the brace means the `{...}` is item *content*
 * (and thus a block attribute for the following block), NOT a list-item
 * attribute. That distinction is what these tests pin down.
 *
 * The older separate-indented-line form (`{...}` on its own line under the item)
 * is soft-deprecated but still attaches to the `<li>` for back-compat; the
 * regression cases below guard that.
 */
class ListItemMarkerAttributesTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Bullet markers ====================

    public function testBulletPlusClassAttribute(): void
    {
        $result = $this->converter->convert("+{.blue} A blue list item.\n");

        $this->assertSame(
            "<ul>\n<li class=\"blue\">\nA blue list item.\n</li>\n</ul>\n",
            $result,
        );
    }

    public function testBulletHyphenIdAttribute(): void
    {
        $result = $this->converter->convert("-{#foo} item\n");

        $this->assertSame(
            "<ul>\n<li id=\"foo\">\nitem\n</li>\n</ul>\n",
            $result,
        );
    }

    public function testBulletKeyValueAttribute(): void
    {
        $result = $this->converter->convert("-{data-x=\"y\"} item\n");

        $this->assertSame(
            "<ul>\n<li data-x=\"y\">\nitem\n</li>\n</ul>\n",
            $result,
        );
    }

    public function testBulletMultipleAttributes(): void
    {
        $result = $this->converter->convert("-{#foo .bar .baz} item\n");

        $this->assertSame(
            "<ul>\n<li id=\"foo\" class=\"bar baz\">\nitem\n</li>\n</ul>\n",
            $result,
        );
    }

    public function testBareBulletMarkerWithAttribute(): void
    {
        $result = $this->converter->convert("+{.blue}\n");

        $this->assertSame(
            "<ul>\n<li class=\"blue\">\n</li>\n</ul>\n",
            $result,
        );
    }

    // ==================== Ordered markers ====================

    public function testOrderedNumericClassAttribute(): void
    {
        $result = $this->converter->convert("1.{.cls} item\n");

        $this->assertSame(
            "<ol>\n<li class=\"cls\">\nitem\n</li>\n</ol>\n",
            $result,
        );
    }

    public function testOrderedParenAlphaClassAttribute(): void
    {
        $result = $this->converter->convert("(a){.bar} Ordered list item with an attribute.\n");

        $this->assertSame(
            "<ol type=\"a\">\n<li class=\"bar\">\nOrdered list item with an attribute.\n</li>\n</ol>\n",
            $result,
        );
    }

    public function testAlphaDotClassAttribute(): void
    {
        $result = $this->converter->convert("a.{.cls} item\n");

        $this->assertSame(
            "<ol type=\"a\">\n<li class=\"cls\">\nitem\n</li>\n</ol>\n",
            $result,
        );
    }

    public function testTaskListMarkerAttribute(): void
    {
        $result = $this->converter->convert("- [x]{.done} finish it\n");

        $this->assertStringContainsString('class="done"', $result);
        $this->assertStringContainsString('checked', $result);
    }

    // ==================== Mixed lists ====================

    public function testOnlyMarkedItemGetsAttribute(): void
    {
        $result = $this->converter->convert("- a\n-{.x} b\n- c\n");

        $this->assertSame(
            "<ul>\n<li>\na\n</li>\n<li class=\"x\">\nb\n</li>\n<li>\nc\n</li>\n</ul>\n",
            $result,
        );
    }

    // ==================== Adjacency rule (space => NOT an item attribute) ====================

    public function testSpaceBeforeBraceIsNotListItemAttribute(): void
    {
        // A space between marker and brace means the brace is item content, so it
        // must NOT become a class on the <li>.
        $result = $this->converter->convert("- {.blue} text\n");

        $this->assertStringNotContainsString('<li class="blue">', $result);
    }

    // ==================== Paragraph interruption (blocksInterruptParagraphs) ====================

    public function testAttributedBulletInterruptsParagraph(): void
    {
        $converter = new DjotConverter(blocksInterruptParagraphs: true);

        $result = $converter->convert("para\n-{.x} item\n");

        // Must interrupt the paragraph and open a list, exactly like `- item` does.
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li class="x">', $result);
    }

    public function testAttributedNumericInterruptsParagraph(): void
    {
        $converter = new DjotConverter(blocksInterruptParagraphs: true);

        $result = $converter->convert("para\n1.{.x} item\n");

        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<li class="x">', $result);
    }

    // ==================== Soft-deprecated separate-line form still works ====================

    public function testSeparateLineAttributeStillAttachesToListItem(): void
    {
        $result = $this->converter->convert("- item\n  {.blue}\n");

        $this->assertSame(
            "<ul>\n<li class=\"blue\">\nitem\n</li>\n</ul>\n",
            $result,
        );
    }
}
