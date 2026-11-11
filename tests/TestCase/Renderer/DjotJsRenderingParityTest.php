<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Heading ids, attribute escaping and task items render as djot.js does.
 */
class DjotJsRenderingParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function headingIds(): array
    {
        return [
            'alt text joins the id' => ['# Intro ![logo](l.png)', 'Intro-logo'],
            'alt text alone' => ['# ![s]()', 's'],
            'alt text between other inlines' => ['# A ![b](c) *d*', 'A-b-d'],
            'empty alt text' => ['# Intro ![](l.png)', 'Intro'],
        ];
    }

    #[DataProvider('headingIds')]
    public function testImageAltTextIsPartOfTheHeadingId(string $djot, string $id): void
    {
        $html = (new DjotConverter())->convert($djot);

        $this->assertStringContainsString('<section id="' . $id . '">', $html);
    }

    public function testHeadingWithOnlyAnImageNoLongerFallsBack(): void
    {
        $html = (new DjotConverter())->convert("# ![s]()\n\n# ![s]()\n");

        $this->assertStringContainsString('<section id="s">', $html);
        $this->assertStringContainsString('<section id="s-1">', $html);
    }

    public function testSingleQuoteStaysLiteralInAttributeValues(): void
    {
        $this->assertSame("<p><a href=\"a{'b\">x</a></p>\n", (new DjotConverter())->convert("[x](a{'b)"));
    }

    public function testBothQuoteKindsInOneAttributeValue(): void
    {
        $html = (new DjotConverter())->convert('[x]{k="a\'b\\"c"}');

        $this->assertSame("<p><span k=\"a'b&quot;c\">x</span></p>\n", $html);
    }

    public function testQuotesInImageAttributes(): void
    {
        $html = (new DjotConverter())->convert("![x](c'd){title=\"t'\\\"\"}");

        $this->assertSame("<p><img alt=\"x\" src=\"c'd\" title=\"t'&quot;\"></p>\n", $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyTaskItems(): array
    {
        return [
            'checked' => ["- [x] \n", "<li>\n<input disabled=\"\" type=\"checkbox\" checked=\"\"/>\n</li>"],
            'unchecked before a filled item' => [
                "- [ ] \n- [x] a\n",
                "<li>\n<input disabled=\"\" type=\"checkbox\"/>\n</li>\n<li>\n<input disabled=\"\" type=\"checkbox\" checked=\"\"/>\na\n</li>",
            ],
        ];
    }

    #[DataProvider('emptyTaskItems')]
    public function testEmptyTaskItemHasNoBlankLine(string $djot, string $expected): void
    {
        $this->assertStringContainsString($expected, (new DjotConverter())->convert($djot));
    }
}
