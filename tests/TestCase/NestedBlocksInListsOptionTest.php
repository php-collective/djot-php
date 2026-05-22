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
}
