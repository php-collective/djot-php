<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

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
        $this->assertTrue($parser->getNestedBlocksInLists());
    }

    public function testSetSignificantNewlinesFalseDisablesBoth(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $parser->setSignificantNewlines(false);
        $this->assertFalse($parser->getBlocksInterruptParagraphs());
        $this->assertFalse($parser->getNestedBlocksInLists());
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
}
