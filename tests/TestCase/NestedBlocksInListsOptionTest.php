<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\Paragraph;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class NestedBlocksInListsOptionTest extends TestCase
{
    public function testConstructorParameter(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $this->assertTrue($parser->getNestedBlocksInLists());
        $this->assertFalse($parser->getSignificantNewlines());
    }

    public function testSetterAndGetter(): void
    {
        $parser = new BlockParser();

        $result = $parser->setNestedBlocksInLists(true);
        $this->assertSame($parser, $result);
        $this->assertTrue($parser->getNestedBlocksInLists());
        $this->assertFalse($parser->getSignificantNewlines());
    }

    public function testTopLevelParagraphInterruptionStaysDisabled(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("Here is a list:\n- item one\n- item two");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testNestedListWithoutBlankLineStillWorks(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("- Item\n    - Unterpunkt\n    - Noch einer");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);

        $item = $list->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testNestedBlockquoteWithoutBlankLineStillWorks(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("- Item\n\t> quoted\n\t> still quoted");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);

        $item = $list->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testNestedListItemAttributeBeforeBlockquote(): void
    {
        $converter = DjotConverter::withNestedBlocksInLists();
        $result = $converter->convert("- outer\n  - inner\n    {.x}\n    > q\n");

        $this->assertSame(2, substr_count($result, '<ul>'));
        $this->assertStringContainsString('<blockquote class="x">', $result);
        $this->assertStringContainsString('<p>q</p>', $result);
        $this->assertStringNotContainsString('&gt; q', $result);
    }

    public function testConverterHelperKeepsTopLevelSpecBehavior(): void
    {
        $converter = DjotConverter::withNestedBlocksInLists();
        $result = $converter->convert("They said:\n> Important\n> Really");

        $this->assertStringNotContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<p>', $result);
    }

    public function testConverterHelperSupportsNestedListWithoutBlankLine(): void
    {
        $converter = DjotConverter::withNestedBlocksInLists();
        $result = $converter->convert("- Item\n\t- Unterpunkt\n\t- Noch einer");

        $this->assertSame(2, substr_count($result, '<ul>'));
        $this->assertStringContainsString('Unterpunkt', $result);
        $this->assertStringContainsString('Noch einer', $result);
    }

    /**
     * Parenthesized ordered markers must nest like "1." markers do. These are
     * recognized by the list-marker parser, so the nested-block detection has
     * to recognize them too (otherwise they degrade to plain paragraph text).
     */
    public function testNestedOrderedListWithParenthesizedMarkers(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("- Item\n    (1) First\n    (2) Second");

        $item = $doc->getChildren()[0]->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
        $this->assertSame(ListBlock::TYPE_ORDERED, $children[1]->getListType());
        $this->assertCount(2, $children[1]->getChildren());
    }

    /**
     * Multi-character roman-numeral markers must nest as a single ordered list,
     * not split across the parent paragraph and a detached sublist.
     */
    public function testNestedOrderedListWithRomanMarkers(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("- Item\n    iv. Fourth\n    v. Fifth");

        $item = $doc->getChildren()[0]->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
        $this->assertSame(ListBlock::TYPE_ORDERED, $children[1]->getListType());
        $this->assertCount(2, $children[1]->getChildren());
    }

    /**
     * When the first nested line is over-indented relative to the item content
     * indent and a following line drops back to the content indent, that
     * following line must stay part of the list item instead of detaching into
     * a top-level paragraph (which would also terminate the list early).
     */
    public function testOverIndentedNestedBlockKeepsTrailingItemContent(): void
    {
        $parser = new BlockParser(nestedBlocksInLists: true);
        $doc = $parser->parse("- a\n      - x\n  more text");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(ListBlock::class, $children[0]);
    }
}
