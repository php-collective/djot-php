<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Heading;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Document;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Text;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

class HtmlRendererTest extends TestCase
{
    protected HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HtmlRenderer();
    }

    public function testRenderParagraph(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Hello world'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Hello world</p>\n", $result);
    }

    public function testRenderHeading(): void
    {
        $doc = new Document();
        $heading = new Heading(2);
        $heading->appendChild(new Text('Title'));
        $doc->appendChild($heading);

        $result = $this->renderer->render($doc);

        // Headings are wrapped in <section> tags per djot spec
        $this->assertSame("<section id=\"Title\">\n<h2>Title</h2>\n</section>\n", $result);
    }

    public function testRenderEmphasis(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $em = new Emphasis();
        $em->appendChild(new Text('emphasized'));
        $para->appendChild($em);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><em>emphasized</em></p>\n", $result);
    }

    public function testRenderStrong(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $strong = new Strong();
        $strong->appendChild(new Text('bold'));
        $para->appendChild($strong);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><strong>bold</strong></p>\n", $result);
    }

    public function testRenderLink(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $link = new Link('https://example.com');
        $link->appendChild(new Text('Example'));
        $para->appendChild($link);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><a href=\"https://example.com\">Example</a></p>\n", $result);
    }

    public function testRenderLinkEscapesAttributeValues(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $link = new Link('https://example.com" onclick="alert(1)', 'Title "quoted"');
        $link->appendChild(new Text('Example'));
        $para->appendChild($link);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame(
            "<p><a href=\"https://example.com&quot; onclick=&quot;alert(1)\" title=\"Title &quot;quoted&quot;\">Example</a></p>\n",
            $result,
        );
    }

    public function testRenderImage(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $image = new Image('photo.jpg', 'A photo');
        $para->appendChild($image);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><img alt=\"A photo\" src=\"photo.jpg\"></p>\n", $result);
    }

    public function testRenderImageEscapesAttributeValues(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $image = new Image('photo.jpg" onerror="alert(1)', 'A "photo"', 'Title "quoted"');
        $para->appendChild($image);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame(
            "<p><img alt=\"A &quot;photo&quot;\" src=\"photo.jpg&quot; onerror=&quot;alert(1)\" title=\"Title &quot;quoted&quot;\"></p>\n",
            $result,
        );
    }

    public function testRenderCode(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $code = new Code('$var');
        $para->appendChild($code);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><code>\$var</code></p>\n", $result);
    }

    public function testRenderCodeBlock(): void
    {
        $doc = new Document();
        $codeBlock = new CodeBlock("echo 'hello';", 'php');
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        $this->assertSame("<pre><code class=\"language-php\">echo 'hello';\n</code></pre>\n", $result);
    }

    public function testRenderCodeBlockEscapesLanguageAttribute(): void
    {
        $doc = new Document();
        $codeBlock = new CodeBlock('echo 1;', 'php" onclick="alert(1)');
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        $this->assertSame(
            "<pre><code class=\"language-php&quot; onclick=&quot;alert(1)\">echo 1;\n</code></pre>\n",
            $result,
        );
    }

    public function testRenderCodeBlockWithoutLanguage(): void
    {
        $doc = new Document();
        $codeBlock = new CodeBlock('plain code');
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        $this->assertSame("<pre><code>plain code\n</code></pre>\n", $result);
    }

    public function testRenderNodeFragmentUsesActiveRenderContext(): void
    {
        $doc = new Document();

        $headingOne = new Heading(1);
        $headingOne->appendChild(new Text('Repeat'));
        $doc->appendChild($headingOne);

        $renderer = $this->renderer;
        $renderer->on('render.heading', function ($event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Heading || $node->getLevel() !== 1) {
                return;
            }

            $fragmentHeading = new Heading(2);
            $fragmentHeading->appendChild(new Text('Repeat'));

            $event->setHtml($renderer->renderNodeFragment($fragmentHeading));
        });

        $headingTwo = new Heading(2);
        $headingTwo->appendChild(new Text('Repeat'));
        $doc->appendChild($headingTwo);

        $result = $renderer->render($doc);

        $this->assertStringContainsString('<h2 id="Repeat">Repeat</h2>', $result);
        $this->assertStringContainsString('<section id="Repeat-1">', $result);
    }

    public function testRenderDocumentFragmentUsesActiveFootnoteState(): void
    {
        $doc = new Document();

        $firstParagraph = new Paragraph();
        $firstParagraph->appendChild(new Text('Before'));
        $firstParagraph->appendChild(new FootnoteRef('a'));
        $doc->appendChild($firstParagraph);

        $renderer = $this->renderer;
        $renderer->on('render.paragraph', function ($event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Paragraph) {
                return;
            }

            $text = '';
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Text) {
                    $text .= $child->getContent();
                }
            }

            if ($text !== 'Trigger') {
                return;
            }

            $fragmentDoc = new Document();
            $fragmentParagraph = new Paragraph();
            $fragmentParagraph->appendChild(new Text('Inside'));
            $fragmentParagraph->appendChild(new FootnoteRef('b'));
            $fragmentDoc->appendChild($fragmentParagraph);

            $event->setHtml($renderer->renderDocumentFragment($fragmentDoc));
        });

        $triggerParagraph = new Paragraph();
        $triggerParagraph->appendChild(new Text('Trigger'));
        $doc->appendChild($triggerParagraph);

        $footnoteA = new \Djot\Node\Block\Footnote('a');
        $footnoteParaA = new Paragraph();
        $footnoteParaA->appendChild(new Text('Alpha'));
        $footnoteA->appendChild($footnoteParaA);
        $doc->appendChild($footnoteA);

        $footnoteB = new \Djot\Node\Block\Footnote('b');
        $footnoteParaB = new Paragraph();
        $footnoteParaB->appendChild(new Text('Beta'));
        $footnoteB->appendChild($footnoteParaB);
        $doc->appendChild($footnoteB);

        $result = $renderer->render($doc);

        $this->assertStringContainsString('id="fnref1"', $result);
        $this->assertStringContainsString('id="fnref2"', $result);
        $this->assertStringContainsString('<li id="fn1">', $result);
        $this->assertStringContainsString('<li id="fn2">', $result);
        $this->assertStringContainsString('Alpha', $result);
        $this->assertStringContainsString('Beta', $result);
    }

    public function testRenderHardBreak(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new HardBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Line 1<br>\nLine 2</p>\n", $result);
    }

    public function testRenderSoftBreakDefault(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new SoftBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        // Default: soft break as newline (djot preserves newlines)
        $this->assertSame("<p>Line 1\nLine 2</p>\n", $result);
    }

    public function testRenderSoftBreakAsSpace(): void
    {
        $this->renderer->setSoftBreakMode(SoftBreakMode::Space);

        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new SoftBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Line 1 Line 2</p>\n", $result);
    }

    public function testRenderSoftBreakAsBr(): void
    {
        $this->renderer->setSoftBreakMode(SoftBreakMode::Break);

        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new SoftBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Line 1<br>\nLine 2</p>\n", $result);
    }

    public function testRenderSoftBreakAsBrXhtml(): void
    {
        $this->renderer = new HtmlRenderer(xhtml: true);
        $this->renderer->setSoftBreakMode(SoftBreakMode::Break);

        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new SoftBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Line 1<br />\nLine 2</p>\n", $result);
    }

    public function testGetSoftBreakMode(): void
    {
        $this->assertSame(SoftBreakMode::Newline, $this->renderer->getSoftBreakMode());

        $this->renderer->setSoftBreakMode(SoftBreakMode::Break);
        $this->assertSame(SoftBreakMode::Break, $this->renderer->getSoftBreakMode());
    }

    public function testRenderWithAttributes(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $para->setAttribute('id', 'intro');
        $para->addClass('highlight');
        $para->appendChild(new Text('Styled paragraph'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertStringContainsString('id="intro"', $result);
        $this->assertStringContainsString('class="highlight"', $result);
    }

    public function testEscapeHtmlEntities(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('<script>alert("xss")</script>'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testLineBlockMergesExistingClasses(): void
    {
        $doc = new Document();
        $lineBlock = new LineBlock();
        $lineBlock->addClass('custom');

        $para = new Paragraph();
        $para->appendChild(new Text('line'));
        $lineBlock->appendChild($para);
        $doc->appendChild($lineBlock);

        $result = $this->renderer->render($doc);

        $this->assertSame("<div class=\"custom line-block\">\n<p>line</p>\n</div>\n", $result);
    }

    public function testTableCellMergesExistingStyle(): void
    {
        $doc = new Document();
        $cell = new TableCell(false, TableCell::ALIGN_CENTER);
        $cell->setAttribute('style', 'color:red');
        $cell->appendChild(new Text('cell'));

        $row = new TableRow();
        $row->appendChild($cell);

        $table = new Table();
        $table->appendChild($row);
        $doc->appendChild($table);

        $result = $this->renderer->render($doc);

        $this->assertSame(
            "<table>\n<tr>\n<td style=\"color:red; text-align: center;\">cell</td>\n</tr>\n</table>\n",
            $result,
        );
    }

    public function testXhtmlMode(): void
    {
        $renderer = new HtmlRenderer(xhtml: true);

        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new HardBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $renderer->render($doc);

        $this->assertStringContainsString('<br />', $result);
    }

    public function testNestedInlineElements(): void
    {
        $doc = new Document();
        $para = new Paragraph();
        $strong = new Strong();
        $em = new Emphasis();
        $em->appendChild(new Text('bold and italic'));
        $strong->appendChild($em);
        $para->appendChild($strong);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p><strong><em>bold and italic</em></strong></p>\n", $result);
    }

    public function testCodeBlockTabWidthDefault(): void
    {
        $doc = new Document();
        $codeBlock = new CodeBlock("if (true) {\n\treturn;\n}");
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        // Default: tabs are converted to 4 spaces
        $this->assertStringNotContainsString("\t", $result);
        $this->assertStringContainsString('    return;', $result);
    }

    public function testCodeBlockTabWidthConvertsToSpaces(): void
    {
        $this->renderer->setCodeBlockTabWidth(4);

        $doc = new Document();
        $codeBlock = new CodeBlock("if (true) {\n\treturn;\n}");
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        // Tabs converted to 4 spaces
        $this->assertStringNotContainsString("\t", $result);
        $this->assertStringContainsString('    return;', $result);
    }

    public function testCodeBlockTabWidthMultipleTabs(): void
    {
        $this->renderer->setCodeBlockTabWidth(2);

        $doc = new Document();
        $codeBlock = new CodeBlock("\t\tindented");
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        // Two tabs = 4 spaces (2 spaces each)
        $this->assertStringContainsString('    indented', $result);
    }

    public function testInlineCodeTabWidthConvertsToSpaces(): void
    {
        $this->renderer->setCodeBlockTabWidth(4);

        $doc = new Document();
        $para = new Paragraph();
        $code = new Code("a\tb");
        $para->appendChild($code);
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        // Tabs converted in inline code too
        $this->assertStringNotContainsString("\t", $result);
        $this->assertStringContainsString('a    b', $result);
    }

    public function testGetCodeBlockTabWidth(): void
    {
        // Default is 4 spaces
        $this->assertSame(4, $this->renderer->getCodeBlockTabWidth());

        $this->renderer->setCodeBlockTabWidth(2);
        $this->assertSame(2, $this->renderer->getCodeBlockTabWidth());

        $this->renderer->setCodeBlockTabWidth(null);
        $this->assertNull($this->renderer->getCodeBlockTabWidth());
    }
}
