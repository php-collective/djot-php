<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\FigureGroupExtension;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use Djot\Renderer\RendererInterface;
use PHPUnit\Framework\TestCase;

class FigureGroupExtensionTest extends TestCase
{
    public function testFigureDivIsUnchangedWithoutExtension(): void
    {
        $html = (new DjotConverter())->convert("::: figure\nContent.\n:::\n");

        $this->assertSame("<div class=\"figure\">\n<p>Content.</p>\n</div>\n", $html);
    }

    public function testCaptionedImagesBecomePanels(): void
    {
        $html = $this->convert(<<<'DJOT'
::: figure
![a](a.png)
^ Panel a

![b](b.png)
^ Panel b
:::
DJOT);

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
<figure class="figure-panel">
<img alt="a" src="a.png"><figcaption>Panel a</figcaption>
</figure>
<figure class="figure-panel">
<img alt="b" src="b.png"><figcaption>Panel b</figcaption>
</figure>
</div>
</figure>
HTML . "\n", $html);
    }

    public function testTrailingCaptionIsAdoptedAfterPanels(): void
    {
        $html = $this->convert("::: figure\n:::\n^ Group caption\n");

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
</div>
<figcaption>Group caption</figcaption>
</figure>
HTML . "\n", $html);
    }

    public function testEscapedCaretIsNotAdopted(): void
    {
        $html = $this->convert("::: figure\n:::\n\\^ Group caption\n");

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
</div>
</figure>
<p>^ Group caption</p>
HTML . "\n", $html);
    }

    public function testOrdinaryFollowingParagraphIsNotAdopted(): void
    {
        $html = $this->convert("::: figure\n:::\nOrdinary paragraph.\n");

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
</div>
</figure>
<p>Ordinary paragraph.</p>
HTML . "\n", $html);
    }

    public function testGroupPreservesIdAndExtraClasses(): void
    {
        $html = $this->convert("{#fig-x .columns-2}\n::: figure\n:::\n");

        $this->assertSame(<<<'HTML'
<figure class="figure-group columns-2" id="fig-x">
<div class="figure-panels">
</div>
</figure>
HTML . "\n", $html);
    }

    public function testStrayContentStaysBetweenPanels(): void
    {
        $html = $this->convert(<<<'DJOT'
::: figure
![a](a.png)
^ A

Stray content.

![b](b.png)
^ B
:::
DJOT);

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
<figure class="figure-panel">
<img alt="a" src="a.png"><figcaption>A</figcaption>
</figure>
<p>Stray content.</p>
<figure class="figure-panel">
<img alt="b" src="b.png"><figcaption>B</figcaption>
</figure>
</div>
</figure>
HTML . "\n", $html);
    }

    public function testCaptionedTableIsWrappedAndKeepsCaption(): void
    {
        $html = $this->convert(<<<'DJOT'
::: figure
| A | B |
|---|---|
| 1 | 2 |
^ Numbers
:::
DJOT);

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
<figure class="figure-panel">
<table>
<caption>Numbers</caption>
<tr>
<th>A</th>
<th>B</th>
</tr>
<tr>
<td>1</td>
<td>2</td>
</tr>
</table>
</figure>
</div>
</figure>
HTML . "\n", $html);
    }

    public function testNestedFigureDivIsNotTransformed(): void
    {
        $html = $this->convert(":::: figure\n::: figure\ninside\n:::\n::::\n");

        $this->assertSame(<<<'HTML'
<figure class="figure-group">
<div class="figure-panels">
<div class="figure">
<p>inside</p>
</div>
</div>
</figure>
HTML . "\n", $html);
    }

    public function testOtherDivAndFollowingCaretParagraphAreUntouched(): void
    {
        $html = $this->convert("::: note\nNote.\n:::\n^ Not a caption\n");

        $this->assertSame(<<<'HTML'
<div class="note">
<p>Note.</p>
</div>
<p>^ Not a caption</p>
HTML . "\n", $html);
    }

    public function testNonHtmlRenderersRetainPanelAndGroupCaptions(): void
    {
        $djot = "::: figure\n![a](a.png)\n^ Panel caption\n:::\n^ Group caption\n";

        $markdown = $this->convert($djot, new MarkdownRenderer());
        $plainText = $this->convert($djot, new PlainTextRenderer());

        $this->assertSame("![a](a.png)\n\nPanel caption\n\nGroup caption\n", $markdown);
        $this->assertSame("a\nPanel caption\nGroup caption\n", $plainText);
    }

    public function testFigureGroupInsideBlockquoteIsTransformed(): void
    {
        $html = $this->convert("> ::: figure\n> ![a](a.png)\n> ^ A\n> :::\n");

        $this->assertSame(<<<'HTML'
<blockquote>
<figure class="figure-group">
<div class="figure-panels">
<figure class="figure-panel">
<img alt="a" src="a.png"><figcaption>A</figcaption>
</figure>
</div>
</figure>
</blockquote>
HTML . "\n", $html);
    }

    private function convert(string $djot, ?RendererInterface $renderer = null): string
    {
        $converter = $renderer === null
            ? new DjotConverter()
            : new DjotConverter(renderer: $renderer);
        $converter->addExtension(new FigureGroupExtension());

        return $converter->convert($djot);
    }
}
