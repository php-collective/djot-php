<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\Paragraph;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class BlocksInterruptParagraphsOptionTest extends TestCase
{
    public function testConstructorParameter(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $this->assertTrue($parser->getBlocksInterruptParagraphs());
        $this->assertFalse($parser->getNestedBlocksInLists());
        // Deprecated getter returns the interruption bit (pure rename).
        $this->assertTrue($parser->getSignificantNewlines());
    }

    public function testSetterAndGetter(): void
    {
        $parser = new BlockParser();
        $result = $parser->setBlocksInterruptParagraphs(true);
        $this->assertSame($parser, $result);
        $this->assertTrue($parser->getBlocksInterruptParagraphs());
        $this->assertFalse($parser->getNestedBlocksInLists());
    }

    public function testTopLevelParagraphInterruptionEnabled(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("Here:\n- one\n- two");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testParagraphNotInterruptedWhenDisabled(): void
    {
        // Default: blocksInterruptParagraphs is off, so a list after prose
        // (no blank line) stays inside the paragraph.
        $parser = new BlockParser();
        $doc = $parser->parse("Here:\n- one\n- two");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testInterruptionWithoutNesting(): void
    {
        // blocksInterruptParagraphs alone must NOT enable list nesting:
        // "- a\n  - b" stays a single list (b is continuation text).
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- a\n  - b");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $item = $list->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertNotInstanceOf(ListBlock::class, $child);
        }
    }

    public function testLoneMarkerRuleStillApplies(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("x = 5\n- 3 + 17");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testSignificantNewlinesEnablesBothLevers(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $this->assertTrue($parser->getBlocksInterruptParagraphs());
        $this->assertTrue($parser->getNestedListsWithoutBlankLine());
        $this->assertFalse($parser->getNestedBlocksInLists());
    }

    public function testSetSignificantNewlinesFalseDisablesBoth(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $parser->setSignificantNewlines(false);
        $this->assertFalse($parser->getBlocksInterruptParagraphs());
        $this->assertFalse($parser->getNestedListsWithoutBlankLine());
    }

    public function testGetSignificantNewlinesReflectsInterruptionBit(): void
    {
        // BC: significantNewlines then turning nesting off must keep the
        // deprecated getter returning true (it tracks the interruption bit).
        $parser = new BlockParser(significantNewlines: true);
        $parser->setNestedBlocksInLists(false);
        $this->assertTrue($parser->getSignificantNewlines());
        $this->assertFalse($parser->getNestedBlocksInLists());
    }

    public function testConverterConstructorParameter(): void
    {
        $converter = new DjotConverter(blocksInterruptParagraphs: true);
        $result = $converter->convert("Here:\n- one\n- two");

        $this->assertStringContainsString('<p>Here:</p>', $result);
        $this->assertStringContainsString('<ul>', $result);
    }

    public function testConverterFactory(): void
    {
        $converter = DjotConverter::withBlocksInterruptParagraphs();
        $result = $converter->convert("Here:\n- one\n- two");

        $this->assertStringContainsString('<ul>', $result);
    }

    public function testConverterFactoryDoesNotNest(): void
    {
        $converter = DjotConverter::withBlocksInterruptParagraphs();
        $result = $converter->convert("- a\n  - b");

        // Interruption-only must not nest: exactly one <ul>.
        $this->assertSame(1, substr_count($result, '<ul>'));
    }

    public function testWithSignificantNewlinesStillEnablesBoth(): void
    {
        $converter = DjotConverter::withSignificantNewlines();
        $result = $converter->convert("- a\n  - b");

        // significantNewlines still nests: two <ul>.
        $this->assertSame(2, substr_count($result, '<ul>'));
    }

    public function testBlockquoteNestsInListItem(): void
    {
        // Semantic expansion: a real (multi-line) blockquote interrupts the
        // item's lead paragraph and nests, the same as at top level.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  > a\n  > b");

        $item = $doc->getChildren()[0]->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testSingleLineBlockquoteInItemStaysLiteralLikeTopLevel(): void
    {
        // Consistency with top-level interruption: a lone "> ..." line is
        // ambiguous (comparison vs quote), so the same lone-marker lookahead
        // the top-level path uses keeps a single-line quote literal inside a
        // list item too. Only a multi-line quote nests (see test above).
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  > quoted");

        $item = $doc->getChildren()[0]->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertNotInstanceOf(BlockQuote::class, $child);
        }
    }

    public function testHeadingNestsInListItem(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  # Head");

        $item = $doc->getChildren()[0]->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Heading::class, $children[1]);
    }

    public function testMultiItemSublistNestsInListItem(): void
    {
        // A list is a block, so a real (multi-item) sublist interrupts the
        // item's lead paragraph and nests, the same as at top level.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  - a\n  - b");

        $item = $doc->getChildren()[0]->getChildren()[0];
        $children = $item->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testLoneSublistItemStaysLiteralLikeTopLevel(): void
    {
        // Consistency with top-level interruption: a lone "- x" line is
        // ambiguous (operator vs list), so the same lone-marker lookahead keeps
        // a single-item sublist literal inside a list item. Only a multi-item
        // sublist nests (see test above). nestedListsWithoutBlankLine is the
        // narrow subset that nests even this lone case.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  - sub");

        $item = $doc->getChildren()[0]->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertNotInstanceOf(ListBlock::class, $child);
        }
    }

    public function testSublistMarkerSetMatchesTopLevelInterruption(): void
    {
        // Interruption deliberately recognizes the same list openers as the
        // top-level path: bullets and 1./1) ordered (the CommonMark guard that
        // keeps "2. apples" or "(1) note" prose). A "2." sublist therefore stays
        // literal under interruption alone, exactly as it would at top level,
        // keeping behavior identical across nesting levels.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("- Item\n  2. a\n  3. b");

        $item = $doc->getChildren()[0]->getChildren()[0];
        foreach ($item->getChildren() as $child) {
            $this->assertNotInstanceOf(ListBlock::class, $child);
        }
    }

    public function testNestedListsOptionIsSubsetOfInterruption(): void
    {
        // The narrow lever nests a lone single-item sublist that interruption's
        // lookahead folds in, confirming it as a distinct, blunter subset.
        $narrow = (new BlockParser(nestedListsWithoutBlankLine: true))->parse("- Item\n  - sub");
        $item = $narrow->getChildren()[0]->getChildren()[0];
        $hasSublist = false;
        foreach ($item->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                $hasSublist = true;
            }
        }
        $this->assertTrue($hasSublist);
    }
}
