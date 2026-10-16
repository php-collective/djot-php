<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition is parsed where it occurs, so its body is kept inside
 * containers and when indented, and a marker line that is paragraph text or
 * code defines nothing (matches djot.js).
 */
class FootnoteDefinitionPlacementTest extends TestCase
{
    #[DataProvider('placementProvider')]
    public function testDefinitionPlacement(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function placementProvider(): array
    {
        return [
            'inside a block quote' => [
                "> [^p]: n\n\n[^p]",
                "<blockquote>\n</blockquote>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'indented' => [
                " [^p]: n\n\n[^p]",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'inside a list item' => [
                "- [^p]: n\n\n[^p]",
                "<ul>\n<li>\n</li>\n</ul>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'inside an ordered list item' => [
                "1. [^p]: n\n\n[^p]",
                "<ol>\n<li>\n</li>\n</ol>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'inside another note' => [
                "[^a] [^b]\n\n[^a]: one\n\n  [^b]: inner",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>one<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n<li id=\"fn2\">\n<p>inner<a href=\"#fnref2\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'inside a code block' => [
                "```\n[^p]: n\n```\n\n[^p]",
                "<pre><code>[^p]: n\n</code></pre>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p><a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
            'later definition wins' => [
                "[^a]\n\n[^a]: one\n\n> [^a]: two",
                "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<blockquote>\n</blockquote>\n<section role=\"doc-endnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>two<a href=\"#fnref1\" role=\"doc-backlink\">↩︎</a></p>\n</li>\n</ol>\n</section>\n",
            ],
        ];
    }

    public function testMarkerLineInParagraphDefinesNothing(): void
    {
        $html = (new DjotConverter())->convert("text [^p]\n[^p]: n");

        $this->assertStringContainsString(': n</p>', $html);
        $this->assertStringNotContainsString('<p>n<a', $html);
    }

    public function testNoUndefinedWarningForLaterNestedDefinition(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("[^p] [^q]\n\n> [^p]: n");

        $messages = array_map(static fn ($w): string => $w->getMessage(), $converter->getWarnings());
        $this->assertSame(["Undefined footnote 'q'"], $messages);
    }
}
