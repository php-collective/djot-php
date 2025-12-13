<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Div;
use Djot\Node\Block\Heading;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\Paragraph;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for significant newlines mode
 *
 * This mode provides markdown-like behavior where:
 * - Block elements can interrupt paragraphs without blank lines
 * - Nested blocks in lists don't need preceding blank lines
 * - Soft breaks render as visible <br> tags
 *
 * Ideal for chat messages, comments, and quick notes.
 */
class SignificantNewlinesTest extends TestCase
{
    // ==================== Parser Tests ====================

    public function testDefaultModeIsSpecCompliant(): void
    {
        $parser = new BlockParser();
        $this->assertFalse($parser->getSignificantNewlines());
    }

    public function testSetterAndGetter(): void
    {
        $parser = new BlockParser();

        $result = $parser->setSignificantNewlines(true);
        $this->assertTrue($parser->getSignificantNewlines());
        $this->assertSame($parser, $result); // Fluent interface
    }

    public function testConstructorParameter(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $this->assertTrue($parser->getSignificantNewlines());
    }

    // ==================== Paragraph Interruption Tests ====================

    public function testListInterruptsParagraph(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Here is a list:\n- item one\n- item two");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testBlockquoteInterruptsParagraph(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testOrderedListInterruptsParagraph(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Steps:\n1. First\n2. Second");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testCodeFenceInterruptsParagraph(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Code:\n```\necho hello\n```");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(CodeBlock::class, $children[1]);
    }

    public function testDivInterruptsParagraph(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Note:\n::: warning\nImportant\n:::");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Div::class, $children[1]);
    }

    // ==================== Standard Mode Spec Compliance ====================

    public function testStandardModeBlockquoteDoesNotInterrupt(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("They said:\n> This is important");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testStandardModeOrderedListDoesNotInterrupt(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("Steps:\n1. First\n2. Second");

        $children = $doc->getChildren();
        // Should be a single paragraph (ordered lists don't interrupt in djot)
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    // ==================== Nested Blocks in Lists ====================

    public function testNestedListWithoutBlankLine(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("- Fruits\n  - Apples\n  - Bananas\n- Vegetables");

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertCount(2, $list->getChildren());

        $firstItem = $list->getChildren()[0];
        $children = $firstItem->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testThreeLevelNesting(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("- L1\n  - L2\n    - L3");

        $list = $doc->getChildren()[0];
        $l1Item = $list->getChildren()[0];
        $l2List = $l1Item->getChildren()[1];
        $l2Item = $l2List->getChildren()[0];
        $l3List = $l2Item->getChildren()[1];

        $this->assertInstanceOf(ListBlock::class, $l3List);
    }

    public function testBlockquoteInListWithoutBlankLine(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("- Item\n  > quoted");

        $list = $doc->getChildren()[0];
        $item = $list->getChildren()[0];
        $children = $item->getChildren();

        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(BlockQuote::class, $children[1]);
    }

    public function testMixedListTypes(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("- Unordered\n  1. Ordered\n  2. Second");

        $list = $doc->getChildren()[0];
        $this->assertSame(ListBlock::TYPE_BULLET, $list->getListType());

        $item = $list->getChildren()[0];
        $sublist = $item->getChildren()[1];
        $this->assertSame(ListBlock::TYPE_ORDERED, $sublist->getListType());
    }

    public function testStandardModeNestedListNeedsBlankLine(): void
    {
        $parser = new BlockParser();
        $doc = $parser->parse("- Item\n  - Not a sublist");

        $list = $doc->getChildren()[0];
        $this->assertCount(1, $list->getChildren());
    }

    // ==================== DjotConverter Integration ====================

    public function testConverterWithSignificantNewlines(): void
    {
        $converter = DjotConverter::withSignificantNewlines();

        $djot = "Here is a list:\n- item one\n- item two";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<p>Here is a list:</p>', $result);
        $this->assertStringContainsString('<ul>', $result);
    }

    public function testConverterSoftBreaksRenderAsBr(): void
    {
        $converter = DjotConverter::withSignificantNewlines();

        $djot = "Line one\nLine two";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<br>', $result);
    }

    public function testConverterConstructorParameter(): void
    {
        $converter = new DjotConverter(significantNewlines: true);

        $djot = "They said:\n> Important";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Chat Message Use Case ====================

    public function testChatMessageExample(): void
    {
        $converter = DjotConverter::withSignificantNewlines();

        $djot = <<<'DJOT'
Hey!
Check this out:
- cool feature
- another one
> Someone said this

What do you think?
DJOT;

        $result = $converter->convert($djot);

        // Soft breaks should be <br>
        $this->assertStringContainsString('<br>', $result);
        // List should be separate
        $this->assertStringContainsString('<ul>', $result);
        // Blockquote should be separate
        $this->assertStringContainsString('<blockquote>', $result);
    }

    // ==================== Edge Cases ====================

    public function testEscapedListMarkerNotAList(): void
    {
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Text:\n\\- not a list");

        $children = $doc->getChildren();
        // Escaped dash is not a list marker
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHeadingDoesNotInterruptParagraphInDefaultMode(): void
    {
        // In default (spec-compliant) mode, headings do NOT interrupt paragraphs
        $parser = new BlockParser();
        $doc = $parser->parse("Text\n# Heading");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHeadingInterruptsParagraphInSignificantNewlinesMode(): void
    {
        // In significantNewlines mode, headings CAN interrupt paragraphs
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Text\n# Heading");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(Heading::class, $children[1]);
    }

    // ==================== CommonMark Ordered List Rule ====================

    public function testOnlyOneCanInterruptParagraph(): void
    {
        // Only "1." can interrupt a paragraph (CommonMark rule)
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Steps:\n1. First step");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }

    public function testYearDoesNotBecomeList(): void
    {
        // "1985." should NOT interrupt - prevents years becoming lists
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("My favorite year was\n1985. It was great.");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHighNumberedListDoesNotInterrupt(): void
    {
        // "5." should NOT interrupt paragraphs
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Continue from step\n5. Do this thing");

        $children = $doc->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
    }

    public function testHighNumberedListAfterBlankLine(): void
    {
        // With blank line, any number can start a list
        $parser = new BlockParser(significantNewlines: true);
        $doc = $parser->parse("Continue from step\n\n5. Do this thing");

        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[0]);
        $this->assertInstanceOf(ListBlock::class, $children[1]);
    }
}
