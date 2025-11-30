<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\Div;
use Djot\Node\Block\Heading;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\Table;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\Document;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;
use function str_contains;

class BlockParserTest extends TestCase
{
    protected BlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
    }

    public function testParseParagraph(): void
    {
        $doc = $this->parser->parse('Hello world');

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
    }

    public function testParseMultipleParagraphs(): void
    {
        $doc = $this->parser->parse("First paragraph\n\nSecond paragraph");

        $this->assertCount(2, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[1]);
    }

    public function testParseHeading(): void
    {
        $doc = $this->parser->parse('# Heading 1');

        $this->assertCount(1, $doc->getChildren());
        $heading = $doc->getChildren()[0];
        $this->assertInstanceOf(Heading::class, $heading);
        $this->assertSame(1, $heading->getLevel());
    }

    public function testParseHeadingLevels(): void
    {
        $doc = $this->parser->parse("# H1\n\n## H2\n\n### H3\n\n#### H4\n\n##### H5\n\n###### H6");

        $children = $doc->getChildren();
        $this->assertCount(6, $children);

        for ($i = 0; $i < 6; $i++) {
            $this->assertInstanceOf(Heading::class, $children[$i]);
            $this->assertSame($i + 1, $children[$i]->getLevel());
        }
    }

    public function testParseCodeBlock(): void
    {
        $doc = $this->parser->parse("```php\necho 'hello';\n```");

        $this->assertCount(1, $doc->getChildren());
        $codeBlock = $doc->getChildren()[0];
        $this->assertInstanceOf(CodeBlock::class, $codeBlock);
        $this->assertSame('php', $codeBlock->getLanguage());
        $this->assertSame("echo 'hello';", $codeBlock->getContent());
    }

    public function testParseCodeBlockWithTildes(): void
    {
        $doc = $this->parser->parse("~~~\ncode\n~~~");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(CodeBlock::class, $doc->getChildren()[0]);
    }

    public function testParseBlockQuote(): void
    {
        $doc = $this->parser->parse('> Quoted text');

        $this->assertCount(1, $doc->getChildren());
        $blockquote = $doc->getChildren()[0];
        $this->assertInstanceOf(BlockQuote::class, $blockquote);
    }

    public function testParseNestedBlockQuote(): void
    {
        $doc = $this->parser->parse("> Level 1\n>\n> > Level 2");

        $this->assertCount(1, $doc->getChildren());
        $outer = $doc->getChildren()[0];
        $this->assertInstanceOf(BlockQuote::class, $outer);

        // Should have nested blockquote
        $hasNestedQuote = false;
        foreach ($outer->getChildren() as $child) {
            if ($child instanceof BlockQuote) {
                $hasNestedQuote = true;

                break;
            }
        }
        $this->assertTrue($hasNestedQuote);
    }

    public function testParseUnorderedList(): void
    {
        $doc = $this->parser->parse("- Item 1\n- Item 2\n- Item 3");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
        $this->assertCount(3, $list->getChildren());
    }

    public function testParseOrderedList(): void
    {
        $doc = $this->parser->parse("1. First\n2. Second\n3. Third");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
    }

    public function testParseTaskList(): void
    {
        $doc = $this->parser->parse("- [ ] Unchecked\n- [x] Checked");

        $this->assertCount(1, $doc->getChildren());
        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame('list', $list->getType());
    }

    public function testParseExtendedTaskStates(): void
    {
        $doc = $this->parser->parse(
            "- [ ] Pending\n- [x] Done\n- [-] Cancelled\n- [>] Deferred\n- [?] Question",
        );

        $list = $doc->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame(ListBlock::TYPE_TASK, $list->getListType());

        $items = $list->getChildren();
        $this->assertCount(5, $items);

        // Pending [ ]
        $this->assertInstanceOf(ListItem::class, $items[0]);
        $this->assertSame(ListItem::STATE_PENDING, $items[0]->getTaskState());
        $this->assertFalse($items[0]->getChecked());

        // Done [x]
        $this->assertInstanceOf(ListItem::class, $items[1]);
        $this->assertSame(ListItem::STATE_DONE, $items[1]->getTaskState());
        $this->assertTrue($items[1]->getChecked());

        // Cancelled [-]
        $this->assertInstanceOf(ListItem::class, $items[2]);
        $this->assertSame(ListItem::STATE_CANCELLED, $items[2]->getTaskState());
        $this->assertFalse($items[2]->getChecked());

        // Deferred [>]
        $this->assertInstanceOf(ListItem::class, $items[3]);
        $this->assertSame(ListItem::STATE_DEFERRED, $items[3]->getTaskState());
        $this->assertFalse($items[3]->getChecked());

        // Question [?]
        $this->assertInstanceOf(ListItem::class, $items[4]);
        $this->assertSame(ListItem::STATE_QUESTION, $items[4]->getTaskState());
        $this->assertFalse($items[4]->getChecked());
    }

    public function testParseDefinitionList(): void
    {
        $doc = $this->parser->parse(": Term\n\n    Definition");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(DefinitionList::class, $doc->getChildren()[0]);
    }

    public function testParseThematicBreak(): void
    {
        $doc = $this->parser->parse('---');

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(ThematicBreak::class, $doc->getChildren()[0]);
    }

    public function testParseThematicBreakVariants(): void
    {
        // Note: Spaced variants like "- - -" may be parsed as lists
        $variants = ['---', '***'];

        foreach ($variants as $variant) {
            $doc = $this->parser->parse($variant);
            $this->assertInstanceOf(
                ThematicBreak::class,
                $doc->getChildren()[0],
                "Failed for variant: $variant",
            );
        }
    }

    public function testParseFencedDiv(): void
    {
        $doc = $this->parser->parse(":::\nContent\n:::");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Div::class, $doc->getChildren()[0]);
    }

    public function testParseFencedDivWithClass(): void
    {
        $doc = $this->parser->parse("::: warning\nContent\n:::");

        $div = $doc->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $class = $div->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'warning'));
    }

    public function testParseTable(): void
    {
        $doc = $this->parser->parse("| A | B |\n|---|---|\n| 1 | 2 |");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Table::class, $doc->getChildren()[0]);
    }

    public function testParseBlockAttributes(): void
    {
        $doc = $this->parser->parse("{.highlight}\n# Heading");

        $heading = $doc->getChildren()[0];
        $this->assertInstanceOf(Heading::class, $heading);
        $class = $heading->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'highlight'));
    }

    public function testParseBlockAttributeWithId(): void
    {
        $doc = $this->parser->parse("{#intro}\nParagraph text");

        $para = $doc->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $para);
        $this->assertSame('intro', $para->getAttribute('id'));
    }

    public function testParseReferenceDefinition(): void
    {
        $doc = $this->parser->parse("[example]: https://example.com\n\n[example][]");

        $ref = $this->parser->getReference('example');
        $this->assertNotNull($ref);
        $this->assertSame('https://example.com', $ref->url);
    }

    public function testParseFootnoteDefinition(): void
    {
        $doc = $this->parser->parse("[^note]: Footnote content\n\nText[^note]");

        $this->assertTrue($this->parser->hasFootnote('note'));
    }

    public function testEmptyInput(): void
    {
        $doc = $this->parser->parse('');

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(0, $doc->getChildren());
    }

    public function testWhitespaceOnlyInput(): void
    {
        $doc = $this->parser->parse("   \n\n   \n");

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertCount(0, $doc->getChildren());
    }
}
