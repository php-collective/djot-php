<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

class AnsiRendererTest extends TestCase
{
    protected DjotConverter $converter;

    protected AnsiRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->renderer = new AnsiRenderer();
    }

    public function testRenderHeading(): void
    {
        $doc = $this->converter->parse("# Heading 1\n\n## Heading 2");
        $output = $this->renderer->render($doc);

        // Check that headings are styled (contain ANSI codes)
        $this->assertStringContainsString("\033[", $output);
        $this->assertStringContainsString('Heading 1', $output);
        $this->assertStringContainsString('Heading 2', $output);
    }

    public function testRenderEmphasisAndStrong(): void
    {
        $doc = $this->converter->parse('This is _emphasized_ and *strong* text.');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('emphasized', $output);
        $this->assertStringContainsString('strong', $output);
        // Check for italic code
        $this->assertStringContainsString("\033[3m", $output);
        // Check for bold code
        $this->assertStringContainsString("\033[1m", $output);
    }

    public function testRenderInlineCode(): void
    {
        $doc = $this->converter->parse('Use the `print()` function.');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('print()', $output);
        // Should have yellow color for code
        $this->assertStringContainsString("\033[93m", $output);
    }

    public function testRenderCodeBlock(): void
    {
        $doc = $this->converter->parse("```php\necho 'hello';\n```");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString("echo 'hello';", $output);
        $this->assertStringContainsString('php', $output);
    }

    public function testRenderLink(): void
    {
        $doc = $this->converter->parse('Visit [Example](https://example.com).');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('Example', $output);
        $this->assertStringContainsString('https://example.com', $output);
        // Check for underline
        $this->assertStringContainsString("\033[4m", $output);
    }

    public function testRenderImage(): void
    {
        $doc = $this->converter->parse('![A photo](image.jpg)');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('[img:', $output);
        $this->assertStringContainsString('A photo', $output);
    }

    public function testRenderUnorderedList(): void
    {
        $doc = $this->converter->parse("- First\n- Second\n- Third");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
        $this->assertStringContainsString('Third', $output);
        // Should have bullet
        $this->assertStringContainsString('•', $output);
    }

    public function testRenderOrderedList(): void
    {
        $doc = $this->converter->parse("1. First\n2. Second\n3. Third");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('1.', $output);
        $this->assertStringContainsString('2.', $output);
        $this->assertStringContainsString('3.', $output);
    }

    public function testRenderBlockQuote(): void
    {
        $doc = $this->converter->parse("> A wise quote.\n>\n> Second paragraph.");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('A wise quote', $output);
        // Should have quote bar (Unicode)
        $this->assertStringContainsString('│', $output);
    }

    public function testRenderTable(): void
    {
        $doc = $this->converter->parse("| A | B |\n|---|---|\n| 1 | 2 |");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('A', $output);
        $this->assertStringContainsString('B', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('2', $output);
        // Should have box drawing characters
        $this->assertStringContainsString('─', $output);
        $this->assertStringContainsString('│', $output);
    }

    public function testRenderThematicBreak(): void
    {
        $doc = $this->converter->parse("Before\n\n---\n\nAfter");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('After', $output);
        $this->assertStringContainsString('─', $output);
    }

    public function testRenderHighlight(): void
    {
        $doc = $this->converter->parse('This is {=highlighted=} text.');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('highlighted', $output);
        // Check for reverse video
        $this->assertStringContainsString("\033[7m", $output);
    }

    public function testRenderInsertDelete(): void
    {
        $doc = $this->converter->parse('Text with {+insert+} and {-delete-}.');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('insert', $output);
        $this->assertStringContainsString('delete', $output);
        // Check for green (insert) and strikethrough (delete)
        $this->assertStringContainsString("\033[32m", $output);
        $this->assertStringContainsString("\033[9m", $output);
    }

    public function testRenderSuperscriptSubscript(): void
    {
        $doc = $this->converter->parse('E=mc{^2^} and H{~2~}O');
        $output = $this->renderer->render($doc);

        // Should use Unicode super/subscript
        $this->assertStringContainsString('²', $output);
        $this->assertStringContainsString('₂', $output);
    }

    public function testRenderSymbol(): void
    {
        $doc = $this->converter->parse('I :heart: this!');
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('❤', $output);
    }

    public function testRenderDefinitionList(): void
    {
        $doc = $this->converter->parse(": Term\n\n  Definition here.");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('Term', $output);
        $this->assertStringContainsString('Definition here', $output);
    }

    public function testRenderFootnote(): void
    {
        $doc = $this->converter->parse("Text[^1].\n\n[^1]: Footnote content.");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('[1]', $output);
        $this->assertStringContainsString('Footnote content', $output);
    }

    public function testDisableColors(): void
    {
        $renderer = new AnsiRenderer(80, false);
        $doc = $this->converter->parse('*Bold text*');
        $output = $renderer->render($doc);

        // Should not contain any ANSI codes
        $this->assertStringNotContainsString("\033[", $output);
        $this->assertStringContainsString('Bold text', $output);
    }

    public function testDisableUnicode(): void
    {
        $renderer = new AnsiRenderer(80, true, false);
        $doc = $this->converter->parse("- Item 1\n- Item 2");
        $output = $renderer->render($doc);

        // Should use ASCII bullet instead of Unicode
        $this->assertStringContainsString('*', $output);
        $this->assertStringNotContainsString('•', $output);
    }

    public function testSetTerminalWidth(): void
    {
        $renderer = new AnsiRenderer();
        $renderer->setTerminalWidth(40);

        $doc = $this->converter->parse('---');
        $output = $renderer->render($doc);

        // Thematic break should be limited
        $plainLine = preg_replace('/\033\[[0-9;]*m/', '', $output) ?? $output;
        $this->assertLessThanOrEqual(40, mb_strlen(trim($plainLine)));
    }

    public function testFluentInterface(): void
    {
        $renderer = new AnsiRenderer();
        $result = $renderer
            ->setTerminalWidth(100)
            ->setUseColors(false)
            ->setUseUnicode(false);

        $this->assertSame($renderer, $result);
    }

    public function testRenderFigureWithCaption(): void
    {
        $doc = $this->converter->parse("![An image](photo.jpg)\n^ Figure caption here");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('[img:', $output);
        $this->assertStringContainsString('An image', $output);
        $this->assertStringContainsString('Figure caption here', $output);
        // Caption should be styled (italic)
        $this->assertStringContainsString("\033[3m", $output);
    }

    public function testRenderAbbreviation(): void
    {
        $doc = $this->converter->parse("*[HTML]: Hyper Text Markup Language\n\nThe HTML spec.");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('HTML', $output);
        $this->assertStringContainsString('Hyper Text Markup Language', $output);
    }

    public function testRenderSection(): void
    {
        // Section nodes are handled but not currently created by the parser
        // This test verifies the renderer gracefully handles Section nodes
        // by testing the fallback behavior (renderChildren)
        $doc = $this->converter->parse("# Heading\n\nContent here.");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('Heading', $output);
        $this->assertStringContainsString('Content here', $output);
    }

    public function testRenderTableWithCaption(): void
    {
        $doc = $this->converter->parse("| A | B |\n|---|---|\n| 1 | 2 |\n^ Table caption");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('A', $output);
        $this->assertStringContainsString('B', $output);
        $this->assertStringContainsString('Table caption', $output);
    }

    public function testRenderTaskListItem(): void
    {
        $doc = $this->converter->parse("- [x] Completed task\n- [ ] Pending task\n- [_] Also pending");
        $output = $this->renderer->render($doc);

        $this->assertStringContainsString('Completed task', $output);
        $this->assertStringContainsString('Pending task', $output);
        $this->assertStringContainsString('Also pending', $output);
        // Should have checkboxes
        $this->assertStringContainsString('☑', $output);
        $this->assertStringContainsString('☐', $output);
    }
}
