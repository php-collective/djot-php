<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Performance\BorrowedHtmlLayout;
use PHPUnit\Framework\TestCase;

final class BorrowedRouteExpansionTest extends TestCase
{
    public function testUnicodeLettersAndNumbersMatchTheAstAcrossBlockShapes(): void
    {
        foreach (['Café', '東京', 'हिन्दी', '한글', 'é', 'Δοκιμή', 'Привет', '۱۲۳'] as $word) {
            foreach (
                [
                    $word . "\n",
                    "# $word\n\n# $word\n",
                    "- $word\n- next\n",
                    "> $word\n",
                    "| A | B |\n|---|---|\n| $word | next |\n",
                ] as $source
            ) {
                $this->assertAcceptedParity($source);
            }
        }
    }

    public function testUnicodeInlineBoundariesAndSpecialSpacesStayOnTheAst(): void
    {
        foreach (
            [
                "😀\n",
                "a\u{00A0}b\n",
                "a\u{2003}b\n",
                "a\u{202E}b\n",
                "Café *bold*\n",
                "Café _emphasis_\n",
                "東京 [link](https://example.com)\n",
                "Café \"quote\"\n",
                "é'a\n",
                "invalid \xFF\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), bin2hex($source));
        }
    }

    public function testSimpleImagesPreserveBlockShapeAndEscapedAttributes(): void
    {
        foreach (
            [
                "![a picture](https://example.com/image.png)\n",
                "![a & b](a.png)\n",
                "![](./a.png)\n",
                "![a](../a.png)\n",
                "![a](/a?x=1&y=2)\n",
                "# Heading\n\n![a](a.png)\n",
                "before\n\n![a](a.png)\n\nafter\n",
            ] as $source
        ) {
            $this->assertAcceptedParity($source);
        }
    }

    public function testImagesWithInlineSemanticsTitlesOrAttributesStayOnTheAst(): void
    {
        foreach (
            [
                "![_a_](a.png)\n",
                "![a -- b](a.png)\n",
                "![a...b](a.png)\n",
                "![Café](a.png)\n",
                "![a](a.png \"title\")\n",
                "![a](a.png){.decorated}\n",
                "![a](javascript:bad)\n",
                "![a](a.png) and text\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), $source);
            self::assertSame(DjotConverter::create()->convert($source), (new DjotConverter())->convert($source));
        }
    }

    public function testFlatBulletListsMatchTheAst(): void
    {
        foreach (['-', '*', '+'] as $marker) {
            $this->assertAcceptedParity("$marker first\n$marker second\n");
            $this->assertAcceptedParity("# Heading\n\n$marker first\n$marker second\n");
        }
    }

    public function testThematicBreaksAreNotMistakenForBulletItems(): void
    {
        foreach (["* --\n", "-- -\n", "- *-*\n"] as $source) {
            $this->assertAcceptedParity($source);
            self::assertSame("<hr>\n", (new DjotConverter())->convert($source));
        }
        self::assertNull((new BorrowedHtmlLayout())->render("+ -- -\n"));
        self::assertSame(DjotConverter::create()->convert("+ -- -\n"), (new DjotConverter())->convert("+ -- -\n"));
    }

    public function testUnicodeDocumentsWithEmptyHeadingSlugsStayOnTheAst(): void
    {
        $source = "# &\n\nCafé\n";
        self::assertNull((new BorrowedHtmlLayout())->render($source));
        self::assertSame(DjotConverter::create()->convert($source), (new DjotConverter())->convert($source));
    }

    public function testAmbiguousFlatBulletListsStayOnTheAst(): void
    {
        foreach (
            [
                "* first\n\n* second\n",
                "* first\n  * second\n",
                "* first\n\n  * second\n",
                "* first\n- second\n",
                "* first\ncontinuation\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), $source);
        }
    }

    private function assertAcceptedParity(string $source): void
    {
        $attempt = (new BorrowedHtmlLayout())->render($source);
        self::assertNotNull($attempt, $source);
        self::assertSame(DjotConverter::create()->convert($source), $attempt['html'], $source);
        self::assertSame($attempt['html'], (new DjotConverter())->convert($source), $source);
    }
}
