<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;
use Djot\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    protected DjotConverter $converter;

    protected MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->renderer = new MarkdownRenderer();
    }

    public function testBasicParagraph(): void
    {
        $djot = 'Hello world!';
        $document = $this->converter->parse($djot);

        $this->assertSame("Hello world!\n", $this->renderer->render($document));
    }

    public function testEmphasis(): void
    {
        $djot = 'This is _emphasized_ text.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('*emphasized*', $this->renderer->render($document));
    }

    public function testStrong(): void
    {
        $djot = 'This is *strong* text.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('**strong**', $this->renderer->render($document));
    }

    public function testHeadings(): void
    {
        $djot = "# Heading 1\n\n## Heading 2\n\n### Heading 3";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('# Heading 1', $result);
        $this->assertStringContainsString('## Heading 2', $result);
        $this->assertStringContainsString('### Heading 3', $result);
    }

    public function testLinks(): void
    {
        $djot = '[Example](https://example.com)';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('[Example](https://example.com)', $this->renderer->render($document));
    }

    public function testLinkWithTitle(): void
    {
        $djot = '[Example](https://example.com "Title")';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('[Example](https://example.com "Title")', $this->renderer->render($document));
    }

    public function testImages(): void
    {
        $djot = '![Alt text](image.png)';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('![Alt text](image.png)', $this->renderer->render($document));
    }

    public function testCodeBlock(): void
    {
        $djot = "```php\necho \"Hello\";\n```";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('```php', $result);
        $this->assertStringContainsString('echo "Hello";', $result);
        $this->assertStringContainsString('```', $result);
    }

    public function testInlineCode(): void
    {
        $djot = 'Use `print()` function.';
        $document = $this->converter->parse($djot);

        $this->assertStringContainsString('`print()`', $this->renderer->render($document));
    }

    public function testUnorderedList(): void
    {
        $djot = "- Item 1\n- Item 2\n- Item 3";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
        $this->assertStringContainsString('- Item 3', $result);
    }

    public function testOrderedList(): void
    {
        $djot = "1. First\n2. Second\n3. Third";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);
        $this->assertStringContainsString('3. Third', $result);
    }

    public function testTaskList(): void
    {
        $djot = "- [ ] Todo\n- [x] Done";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('[ ]', $result);
        $this->assertStringContainsString('[x]', $result);
    }

    public function testBlockQuote(): void
    {
        $djot = '> This is quoted text.';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('> This is quoted text.', $result);
    }

    public function testThematicBreak(): void
    {
        $djot = "Above\n\n***\n\nBelow";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('---', $result);
    }

    public function testTable(): void
    {
        $djot = "| A | B |\n|---|---|\n| 1 | 2 |";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('| A | B |', $result);
        $this->assertStringContainsString('| --- | --- |', $result);
        $this->assertStringContainsString('| 1 | 2 |', $result);
    }

    public function testTableAlignment(): void
    {
        $djot = "| Left | Center | Right |\n|:-----|:------:|------:|\n| L | C | R |";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString(':---', $result);
        $this->assertStringContainsString(':---:', $result);
        $this->assertStringContainsString('---:', $result);
    }

    public function testSuperscript(): void
    {
        $djot = 'E=mc^2^';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<sup>2</sup>', $result);
    }

    public function testSubscript(): void
    {
        $djot = 'H~2~O';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<sub>2</sub>', $result);
    }

    public function testHighlight(): void
    {
        $djot = 'Text {=highlighted=} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<mark>highlighted</mark>', $result);
    }

    public function testDelete(): void
    {
        $djot = 'Text {-deleted-} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('~~deleted~~', $result);
    }

    public function testInsert(): void
    {
        $djot = 'Text {+inserted+} here';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<ins>inserted</ins>', $result);
    }

    public function testFootnote(): void
    {
        $djot = "Text[^1]\n\n[^1]: Footnote content";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('[^1]', $result);
        $this->assertStringContainsString('[^1]: Footnote content', $result);
    }

    public function testMathInline(): void
    {
        $djot = 'Equation $`E = mc^2` here.';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('$E = mc^2$', $result);
    }

    public function testMathDisplay(): void
    {
        $djot = '$$`x^2 + y^2 = z^2`';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('$$x^2 + y^2 = z^2$$', $result);
    }

    public function testHardBreak(): void
    {
        $djot = "Line 1\\\nLine 2";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // Hard break in Markdown is two spaces before newline
        $this->assertStringContainsString("  \n", $result);
    }

    public function testRawHtml(): void
    {
        $djot = 'Text `<span>raw</span>`{=html} more';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('<span>raw</span>', $result);
    }

    public function testDefinitionList(): void
    {
        $djot = "Term\n: Definition";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // Definition lists approximated with bold + colon prefix
        $this->assertStringContainsString('**Term**', $result);
        $this->assertStringContainsString(': Definition', $result);
    }

    public function testDefinitionListMultipleTermsMultipleDefinitions(): void
    {
        $djot = ": color\n: colour\n\n  The visual property.\n\n  Used in design.";
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        // Multiple terms
        $this->assertStringContainsString('**color**', $result);
        $this->assertStringContainsString('**colour**', $result);
        // Multiple definitions
        $this->assertSame(2, substr_count($result, ': '));
        $this->assertStringContainsString('The visual property.', $result);
        $this->assertStringContainsString('Used in design.', $result);
    }

    public function testComplexDocument(): void
    {
        $djot = <<<'DJOT'
# Welcome

This is a *paragraph* with _emphasis_.

## Features

- Item one
- Item two

```php
echo "Hello";
```
DJOT;

        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('# Welcome', $result);
        $this->assertStringContainsString('**paragraph**', $result);
        $this->assertStringContainsString('*emphasis*', $result);
        $this->assertStringContainsString('## Features', $result);
        $this->assertStringContainsString('- Item one', $result);
        $this->assertStringContainsString('```php', $result);
    }

    public function testEventReplaceContent(): void
    {
        $this->renderer->on('render.symbol', function (RenderEvent $event): void {
            $symbol = $event->getNode();
            if ($symbol instanceof Symbol) {
                $emoji = match ($symbol->getName()) {
                    'heart' => ':heart_emoji:',
                    'star' => ':star_emoji:',
                    default => ':' . $symbol->getName() . ':',
                };
                $event->setHtml($emoji);
            }
        });

        $djot = 'I :heart: Djot!';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString(':heart_emoji:', $result);
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
            $event->setHtml('CUSTOM_HEADING_TEXT');
        });

        $djot = '# Original Title';
        $document = $this->converter->parse($djot);
        $result = $this->renderer->render($document);

        $this->assertStringContainsString('CUSTOM_HEADING_TEXT', $result);
        $this->assertStringNotContainsString('Original Title', $result);
    }
}
