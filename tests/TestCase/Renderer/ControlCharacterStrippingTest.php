<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use Djot\Renderer\RendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ControlCharacterStrippingTest extends TestCase
{
    /**
     * Every construct whose text reaches the output, each carrying C0, DEL or C1 controls.
     *
     * @var string
     */
    protected const DOCUMENT = "# t\e]0;title\x07x\n\n"
        . "a \e[2Jb \x7Fc \u{009B}31md\r\n"
        . "`co\ede` \$`ma\eth` [li\enk](http://ex\eample.com) ![al\et](i.png) :sy\em:\n\n"
        . "[ab\ebr]{abbr=\"ti\etle\"} x[^no\ete]\n\n"
        . "[^no\ete]: body\n\n"
        . "``` py\ethon\ncode\e[31m\n```\n\n"
        . "``` =ht\eml\n<b>raw\e</b>\n```\n\n"
        . "| ce\ell |\n\n"
        . "- li\est\tkeeps tab\n";

    /**
     * @return array<string, array{\Djot\Renderer\RendererInterface}>
     */
    public static function renderers(): array
    {
        return [
            'ansi' => [new AnsiRenderer()],
            'ansi without colors' => [new AnsiRenderer(useColors: false)],
            'plain text' => [new PlainTextRenderer()],
            'markdown' => [new MarkdownRenderer()],
        ];
    }

    #[DataProvider('renderers')]
    public function testDocumentControlsAreRemoved(RendererInterface $renderer): void
    {
        $output = $renderer->render((new DjotConverter())->parse(self::DOCUMENT));

        // Remove the renderer's own SGR styling, then nothing control-like may remain.
        $text = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $text);
        $this->assertStringContainsString("\t", $text);
        $this->assertStringContainsString('b c', $text);
    }

    public function testAnsiRendererKeepsItsOwnStyling(): void
    {
        $output = (new AnsiRenderer())->render((new DjotConverter())->parse("*a\e]8;;x\e\\b*"));

        $this->assertSame(AnsiRenderer::BOLD . 'a]8;;x\\b' . AnsiRenderer::RESET . "\n", $output);
    }

    public function testControlsInUnstyledAnsiTextAreRemoved(): void
    {
        $output = (new AnsiRenderer())->render((new DjotConverter())->parse("a \e]0;pwned\x07 b"));

        $this->assertSame("a ]0;pwned b\n", $output);
    }

    /**
     * Stripping runs after the URL check, so the check must already ignore the controls it strips.
     */
    #[DataProvider('renderers')]
    public function testStrippingCannotAssembleADangerousScheme(RendererInterface $renderer): void
    {
        $document = (new DjotConverter())->parse("[a](java\x7Fscript:x) [b](java\u{0085}script:y)");

        $this->assertStringNotContainsString('script:', $renderer->render($document));
    }
}
