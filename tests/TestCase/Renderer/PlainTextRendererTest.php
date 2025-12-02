<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;
use Djot\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class PlainTextRendererTest extends TestCase
{
    protected DjotConverter $converter;

    protected PlainTextRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->renderer = new PlainTextRenderer();
    }

    public function testBasicParagraph(): void
    {
        $djot = 'Hello world!';
        $document = $this->converter->parse($djot);

        $this->assertSame("Hello world!\n", $this->renderer->render($document));
    }

    public function testEmphasisAndStrong(): void
    {
        $djot = 'Hello *world* and _universe_!';
        $document = $this->converter->parse($djot);

        $this->assertSame("Hello world and universe!\n", $this->renderer->render($document));
    }

    public function testLinks(): void
    {
        $djot = '[Click here](https://example.com) to visit.';
        $document = $this->converter->parse($djot);

        $this->assertSame("Click here to visit.\n", $this->renderer->render($document));
    }

    public function testImages(): void
    {
        $djot = 'See ![a photo](image.jpg) here.';
        $document = $this->converter->parse($djot);

        $this->assertSame("See a photo here.\n", $this->renderer->render($document));
    }

    public function testHeadings(): void
    {
        $djot = "# Welcome\n\nThis is content.";
        $document = $this->converter->parse($djot);

        $this->assertSame("Welcome\n\nThis is content.\n", $this->renderer->render($document));
    }

    public function testMultipleParagraphs(): void
    {
        $djot = "First paragraph.\n\nSecond paragraph.";
        $document = $this->converter->parse($djot);

        $this->assertSame("First paragraph.\n\nSecond paragraph.\n", $this->renderer->render($document));
    }

    public function testUnorderedList(): void
    {
        $djot = "- Item one\n- Item two\n- Item three";
        $document = $this->converter->parse($djot);

        $expected = "- Item one\n- Item two\n- Item three\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testOrderedList(): void
    {
        $djot = "1. First\n2. Second\n3. Third";
        $document = $this->converter->parse($djot);

        $expected = "1. First\n2. Second\n3. Third\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testOrderedListCustomStart(): void
    {
        $djot = "5. Fifth\n6. Sixth";
        $document = $this->converter->parse($djot);

        $expected = "5. Fifth\n6. Sixth\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testCodeBlock(): void
    {
        $djot = "```php\necho \"Hello\";\n```";
        $document = $this->converter->parse($djot);

        $expected = "echo \"Hello\";\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testInlineCode(): void
    {
        $djot = 'Use the `print()` function.';
        $document = $this->converter->parse($djot);

        $this->assertSame("Use the print() function.\n", $this->renderer->render($document));
    }

    public function testTable(): void
    {
        $djot = "| Name | Age |\n|------|-----|\n| Alice | 30 |\n| Bob | 25 |";
        $document = $this->converter->parse($djot);

        $expected = "Name | Age\nAlice | 30\nBob | 25\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testTableWithCaption(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |\n^ User data";
        $document = $this->converter->parse($djot);

        $expected = "A | B\n1 | 2\nUser data\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testDefinitionList(): void
    {
        $djot = "Term\n: Definition here";
        $document = $this->converter->parse($djot);

        $expected = "Term\n  Definition here\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testBlockQuote(): void
    {
        $djot = '> This is quoted text.';
        $document = $this->converter->parse($djot);

        $this->assertSame("\"This is quoted text.\"\n", $this->renderer->render($document));
    }

    public function testBlockQuoteCustomPrefixSuffix(): void
    {
        $this->renderer->setBlockQuotePrefix('« ');
        $this->renderer->setBlockQuoteSuffix(' »');
        $djot = '> This is quoted text.';
        $document = $this->converter->parse($djot);

        $this->assertSame("« This is quoted text. »\n", $this->renderer->render($document));
    }

    public function testThematicBreak(): void
    {
        $djot = "Above\n\n***\n\nBelow";
        $document = $this->converter->parse($djot);

        $expected = "Above\n\n---\n\nBelow\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testHardBreak(): void
    {
        $djot = "Line one\\\nLine two";
        $document = $this->converter->parse($djot);

        $this->assertSame("Line one\nLine two\n", $this->renderer->render($document));
    }

    public function testSuperscriptSubscript(): void
    {
        $djot = 'E=mc^2^ and H~2~O';
        $document = $this->converter->parse($djot);

        $this->assertSame("E=mc2 and H2O\n", $this->renderer->render($document));
    }

    public function testHighlightInsertDelete(): void
    {
        $djot = '{=highlighted=} {+inserted+} {-deleted-}';
        $document = $this->converter->parse($djot);

        $this->assertSame("highlighted inserted deleted\n", $this->renderer->render($document));
    }

    public function testSymbol(): void
    {
        $djot = 'I :heart: Djot';
        $document = $this->converter->parse($djot);

        $this->assertSame("I :heart: Djot\n", $this->renderer->render($document));
    }

    public function testMath(): void
    {
        $djot = 'The equation $`E = mc^2` is famous.';
        $document = $this->converter->parse($djot);

        $this->assertSame("The equation E = mc^2 is famous.\n", $this->renderer->render($document));
    }

    public function testFootnote(): void
    {
        $djot = "Text[^1]\n\n[^1]: Footnote content";
        $document = $this->converter->parse($djot);

        $result = $this->renderer->render($document);
        $this->assertStringContainsString('Text[1]', $result);
        $this->assertStringContainsString('[1]: Footnote content', $result);
    }

    public function testCommentsStripped(): void
    {
        $djot = "Visible\n\n{% This is a comment %}\n\nMore visible";
        $document = $this->converter->parse($djot);

        $result = $this->renderer->render($document);
        $this->assertStringContainsString('Visible', $result);
        $this->assertStringContainsString('More visible', $result);
        $this->assertStringNotContainsString('comment', $result);
    }

    public function testRawHtmlStripped(): void
    {
        $djot = 'Text `<span>raw</span>`{=html} more';
        $document = $this->converter->parse($djot);

        $result = $this->renderer->render($document);
        $this->assertStringContainsString('Text', $result);
        $this->assertStringContainsString('more', $result);
        $this->assertStringNotContainsString('<span>', $result);
    }

    public function testCustomListPrefix(): void
    {
        $this->renderer->setListItemPrefix('* ');
        $djot = "- Item one\n- Item two";
        $document = $this->converter->parse($djot);

        $expected = "* Item one\n* Item two\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testCustomTableSeparator(): void
    {
        $this->renderer->setTableCellSeparator("\t");
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |";
        $document = $this->converter->parse($djot);

        $expected = "A\tB\n1\t2\n";
        $this->assertSame($expected, $this->renderer->render($document));
    }

    public function testComplexDocument(): void
    {
        $djot = <<<'DJOT'
# Welcome

This is the *first* paragraph with a [link](https://example.com).

## Features

- Item one
- Item two

```php
echo "Hello";
```

That's all!
DJOT;

        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('Welcome', $result);
        $this->assertStringContainsString('first paragraph', $result);
        $this->assertStringContainsString('link', $result);
        $this->assertStringContainsString('Features', $result);
        $this->assertStringContainsString('- Item one', $result);
        $this->assertStringContainsString('echo "Hello";', $result);
        $this->assertStringContainsString("That\u{2019}s all!", $result); // Smart quote
        $this->assertStringNotContainsString('*', $result);
        $this->assertStringNotContainsString('<', $result);
    }

    public function testEventReplaceContent(): void
    {
        $this->renderer->on('render.symbol', function (RenderEvent $event): void {
            $symbol = $event->getNode();
            if ($symbol instanceof Symbol) {
                $emoji = match ($symbol->getName()) {
                    'heart' => '[HEART]',
                    'star' => '[STAR]',
                    default => ':' . $symbol->getName() . ':',
                };
                $event->setHtml($emoji);
            }
        });

        $djot = 'I :heart: Djot!';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('[HEART]', $result);
    }

    public function testEventWildcard(): void
    {
        $nodeTypes = [];
        $this->renderer->on('render.*', function (RenderEvent $event) use (&$nodeTypes): void {
            $nodeTypes[] = $event->getNode()->getType();
        });

        $djot = "# Hello\n\nWorld";
        $document = $this->converter->parse($djot);
        $this->renderer->render($document);

        $this->assertContains('heading', $nodeTypes);
        $this->assertContains('paragraph', $nodeTypes);
        $this->assertContains('text', $nodeTypes);
    }

    public function testEventOff(): void
    {
        $called = false;
        $this->renderer->on('render.paragraph', function () use (&$called): void {
            $called = true;
        });

        $this->renderer->off('render.paragraph');
        $djot = 'Test paragraph';
        $document = $this->converter->parse($djot);
        $this->renderer->render($document);

        $this->assertFalse($called);
    }

    public function testEventPreventDefault(): void
    {
        $this->renderer->on('render.heading', function (RenderEvent $event): void {
            $event->setHtml('CUSTOM HEADING');
        });

        $djot = '# Original Title';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('CUSTOM HEADING', $result);
        $this->assertStringNotContainsString('Original Title', $result);
    }
}
