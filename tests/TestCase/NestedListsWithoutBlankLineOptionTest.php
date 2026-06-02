<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

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
}
