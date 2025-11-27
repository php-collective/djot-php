<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Document;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Text;
use Djot\Renderer\HtmlRenderer;
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

        $this->assertSame("<h2>Title</h2>\n", $result);
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

        $this->assertSame("<pre><code class=\"language-php\">echo 'hello';</code></pre>\n", $result);
    }

    public function testRenderCodeBlockWithoutLanguage(): void
    {
        $doc = new Document();
        $codeBlock = new CodeBlock('plain code');
        $doc->appendChild($codeBlock);

        $result = $this->renderer->render($doc);

        $this->assertSame("<pre><code>plain code</code></pre>\n", $result);
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

        // Default: soft break as space
        $this->assertSame("<p>Line 1 Line 2</p>\n", $result);
    }

    public function testRenderSoftBreakAsNewline(): void
    {
        $this->renderer->setSoftBreakAsNewline(true);

        $doc = new Document();
        $para = new Paragraph();
        $para->appendChild(new Text('Line 1'));
        $para->appendChild(new SoftBreak());
        $para->appendChild(new Text('Line 2'));
        $doc->appendChild($para);

        $result = $this->renderer->render($doc);

        $this->assertSame("<p>Line 1\nLine 2</p>\n", $result);
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
}
