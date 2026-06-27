<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Non-HTML renderers must not carry a dangerous URL scheme into their output.
 *
 * The HTML renderer blanks `javascript:` / `vbscript:` / `data:` / `file:` URLs,
 * but a Markdown / plain-text / ANSI export of the same document is markup that
 * gets rendered (or clicked) somewhere else - so the same denylist applies to
 * every output format, not just HTML.
 */
class NonHtmlUrlHardeningTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
            'evasion' => ["java\tscript:alert(1)"],
        ];
    }

    #[DataProvider('dangerousUrlProvider')]
    public function testMarkdownBlanksDangerousLinkUrl(string $url): void
    {
        $converter = DjotConverter::create(renderer: new MarkdownRenderer());
        $result = $converter->convert('[x](' . $url . ')');

        $this->assertStringNotContainsString('script:', $result);
        $this->assertStringNotContainsString('data:', $result);
        $this->assertStringNotContainsString('file:', $result);
        $this->assertStringContainsString('[x]()', $result);
    }

    public function testMarkdownBlanksDangerousImageUrl(): void
    {
        $converter = DjotConverter::create(renderer: new MarkdownRenderer());
        $this->assertStringContainsString('![a]()', $converter->convert('![a](data:text/html,x)'));
    }

    public function testMarkdownPreservesSafeUrl(): void
    {
        $converter = DjotConverter::create(renderer: new MarkdownRenderer());
        $this->assertStringContainsString('[x](https://ok.example)', $converter->convert('[x](https://ok.example)'));
    }

    public function testPlainTextDropsDangerousUrlToLinkText(): void
    {
        $converter = DjotConverter::create(renderer: new PlainTextRenderer());
        $result = $converter->convert('[label](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringContainsString('label', $result);
    }

    public function testAnsiDropsDangerousUrl(): void
    {
        $converter = DjotConverter::create(renderer: new AnsiRenderer());
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $converter->convert('[label](vbscript:x)')) ?? '';

        $this->assertStringNotContainsString('vbscript', $plain);
        $this->assertStringContainsString('label', $plain);
    }
}
