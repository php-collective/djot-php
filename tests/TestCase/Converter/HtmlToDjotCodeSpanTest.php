<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline code-span content must survive HTML -> Djot -> HTML untouched.
 *
 * Djot strips padding independently next to a backtick at either end.
 * Significant spaces in those positions need a raw HTML fallback.
 */
class HtmlToDjotCodeSpanTest extends TestCase
{
    protected HtmlToDjot $converter;

    protected DjotConverter $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new HtmlToDjot();
        $this->renderer = new DjotConverter();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function codeContentProvider(): array
    {
        return [
            'plain' => ['ls -la'],
            'mid backtick' => ['a`b'],
            'leading backtick' => ['`a'],
            'trailing backtick' => ['a`'],
            'both backticks' => ['`a`'],
            'single backtick' => ['`'],
            'leading space' => [' a'],
            'trailing space' => ['a '],
            'both spaces' => [' a '],
            'all spaces' => ['   '],
            'leading space and backtick' => [' `a'],
            'trailing backtick and space' => ['a` '],
            'leading backtick after a space with HTML text' => [' `<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('codeContentProvider')]
    public function testCodeSpanContentRoundTrips(string $content): void
    {
        $html = '<p>x <code>' . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5) . '</code> y</p>';
        $djot = $this->converter->convert($html);
        $reRendered = $this->renderer->convert($djot);

        preg_match('#<code>(.*?)</code>#s', $reRendered, $matches);
        $got = html_entity_decode($matches[1] ?? '(no code element)', ENT_QUOTES | ENT_HTML5);

        $this->assertSame($content, $got, 'Djot intermediate: ' . $djot);
    }

    public function testUnrepresentableCodeSpanUsesEscapedHtml(): void
    {
        $html = '<p><code class="language-php" onclick="alert(1)"> `&lt;script&gt;</code></p>';
        $djot = $this->converter->convert($html);
        $rendered = $this->renderer->convert($djot);

        $this->assertStringContainsString('<code class="language-php"> `&lt;script&gt;</code>', $rendered);
        $this->assertStringNotContainsString('onclick=', $rendered);
    }
}
