<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\Paragraph;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class NestedListsWithoutBlankLineOptionTest extends TestCase
{
    public function testConstructorParameter(): void
    {
        $parser = new BlockParser(nestedListsWithoutBlankLine: true);
        $this->assertTrue($parser->getNestedListsWithoutBlankLine());
        $this->assertFalse($parser->getNestedBlocksInLists());
        $this->assertFalse($parser->getBlocksInterruptParagraphs());
    }

    public function testSetterAndGetter(): void
    {
        $parser = new BlockParser();
        $result = $parser->setNestedListsWithoutBlankLine(true);
        $this->assertSame($parser, $result);
        $this->assertTrue($parser->getNestedListsWithoutBlankLine());
    }

    public function testSublistNestsWithoutBlankLine(): void
    {
        $parser = new BlockParser(nestedListsWithoutBlankLine: true);
        $doc = $parser->parse("- Item\n  - Nested A\n  - Nested B");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $item = $list->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testBlockquoteInItemStaysLiteral(): void
    {
        // Narrow flag = sublists only; a blockquote under the item must NOT nest.
        $parser = new BlockParser(nestedListsWithoutBlankLine: true);
        $doc = $parser->parse("- Item\n  > quoted");

        $item = $doc->getChildren()[0]->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertInstanceOf(Paragraph::class, $child);
        }
    }

    public function testHeadingInItemStaysLiteral(): void
    {
        $parser = new BlockParser(nestedListsWithoutBlankLine: true);
        $doc = $parser->parse("- Item\n  # Head");

        $item = $doc->getChildren()[0]->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertInstanceOf(Paragraph::class, $child);
        }
    }

    public function testTopLevelParagraphNotInterrupted(): void
    {
        $parser = new BlockParser(nestedListsWithoutBlankLine: true);
        $doc = $parser->parse("Here is a list:\n- one\n- two");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testConverterConstructorParameter(): void
    {
        $converter = new DjotConverter(nestedListsWithoutBlankLine: true);
        $result = $converter->convert("- Item\n  - Nested A\n  - Nested B");

        $this->assertSame(2, substr_count($result, '<ul>'));
    }

    public function testConverterFactory(): void
    {
        $converter = DjotConverter::withNestedListsWithoutBlankLine();
        $result = $converter->convert("- Item\n  - Nested A");

        $this->assertSame(2, substr_count($result, '<ul>'));
    }

    public function testConverterFactoryDoesNotNestBlockquote(): void
    {
        $converter = DjotConverter::withNestedListsWithoutBlankLine();
        $result = $converter->convert("- Item\n  > quoted");

        $this->assertStringNotContainsString('<blockquote>', $result);
    }

    public function testBothLeversReproduceSignificantNewlines(): void
    {
        $input = "Here is a list:\n- one\n- two\n\n- Item\n  - Nested\n  > quoted";

        $granular = (new DjotConverter(
            blocksInterruptParagraphs: true,
            nestedListsWithoutBlankLine: true,
        ))->convert($input);

        $significant = DjotConverter::withSignificantNewlines()->convert($input);

        $this->assertSame($significant, $granular);
    }

    public function testDeprecatedNestedBlocksInListsStillNestsAllBlockTypes(): void
    {
        // Regression guard for the broad deprecated flag.
        $converter = DjotConverter::withNestedBlocksInLists();
        $result = $converter->convert("- Item\n  > quoted");

        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testSignificantNewlinesOutputUnchanged(): void
    {
        // Regression guard: broad behavior still nests sublists.
        $converter = DjotConverter::withSignificantNewlines();
        $result = $converter->convert("- a\n  - b");

        $this->assertSame(2, substr_count($result, '<ul>'));
    }

    public function testBothLeversComposeAdditively(): void
    {
        // Both granular levers on: sublist nests (list flag) AND blockquote
        // nests (interrupt flag) inside the same item.
        $converter = new DjotConverter(
            blocksInterruptParagraphs: true,
            nestedListsWithoutBlankLine: true,
        );
        $result = $converter->convert("- Item\n  - sub\n\n- Two\n  > quoted");

        $this->assertSame(2, substr_count($result, '<ul>'));
        $this->assertStringContainsString('<blockquote>', $result);
    }

    public function testLoneMarkerInItemUnderInterruptionIsScoped(): void
    {
        // Behavior-locking test (documents current SCOPED behavior, not an
        // endorsement): the in-list interrupt path uses a single-line block
        // check, so a lone-marker-style line under a list item with
        // blocksInterruptParagraphs is handled by the current implementation.
        // This test pins whatever the current output is so future changes to
        // the lone-marker handling are caught deliberately rather than by
        // accident. Assert against the ACTUAL current output.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- if x\n  > 5 then");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);

        // Observed current behavior: under blocksInterruptParagraphs the
        // "  > 5 then" line nests as a BlockQuote inside the item (it is NOT
        // kept as literal paragraph text). Item children are [Paragraph, BlockQuote].
        $item = $list->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }
}
