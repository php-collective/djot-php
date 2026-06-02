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
}
