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
 * Djot strips a single leading and trailing space from a code span only when
 * both ends have one and the span is not all spaces. So content that begins or
 * ends with a backtick (which needs a separating space) must be padded on both
 * ends, not one; otherwise a lone pad space is kept and the content gains a
 * space. Likewise, content that already begins or ends with a significant space
 * must be padded so the strip rule restores it rather than eating it.
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
}
