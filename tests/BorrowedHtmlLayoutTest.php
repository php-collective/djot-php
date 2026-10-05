<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use Djot\Performance\BorrowedHtmlLayout;
use Djot\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BorrowedHtmlLayoutTest extends TestCase
{
    public function testLargePlainDocumentsStayBorrowedAndPreserveLateFallback(): void
    {
        foreach ([65535, 65536, 65537, 262144] as $size) {
            $source = str_repeat('x', $size) . "\n";
            $attempt = (new BorrowedHtmlLayout())->render($source);
            self::assertNotNull($attempt);
            self::assertSame(DjotConverter::create()->convert($source), $attempt['html']);
            self::assertSame($attempt['html'], (new DjotConverter())->convert($source));
        }
        $source = str_repeat("café paragraph\n\n", 8192);
        $attempt = (new BorrowedHtmlLayout())->render($source);
        self::assertNotNull($attempt);
        self::assertSame(DjotConverter::create()->convert($source), $attempt['html']);
        $prefix = str_repeat("plain paragraph\n\n", 4096);
        foreach (["_emphasis_\n", "😀\n", "- loose\n\n- list\n", "paragraph \n", "*bold*\n", "1. item\n", "# heading\n", "(c)\n", "...\n", "--\n"] as $tail) {
            $source = $prefix . $tail;
            self::assertNull((new BorrowedHtmlLayout())->render($source));
            self::assertSame(DjotConverter::create()->convert($source), (new DjotConverter())->convert($source));
        }
    }

    public function testAMarkerAfterTheSameMarkerCloserUsesTheParser(): void
    {
        foreach (["_x__y_\n", "*x**y*\n", "a _x__y_ b\n", "_x__y__z_\n"] as $source) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), $source);
            self::assertSame(
                DjotConverter::create()->convert($source),
                (new DjotConverter())->convert($source),
                $source,
            );
        }
    }

    #[DataProvider('acceptedDocuments')]
    public function testAcceptedDocumentsAreByteIdenticalToTheAstPipeline(string $source): void
    {
        $borrowed = (new BorrowedHtmlLayout())->render($source);

        self::assertNotNull($borrowed);
        self::assertSame(DjotConverter::create()->convert($source), $borrowed['html']);
        self::assertSame((new DjotConverter())->convert($source), $borrowed['html']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedDocuments(): iterable
    {
        yield 'unicode letters' => ["Grüße\n"];
        yield 'plain paragraphs' => ["First paragraph.\ncontinues here.\n\nSecond paragraph.\n"];
        yield 'smart punctuation' => ["A \"quote\", don't stop--now.\n"];
        yield 'core inline' => ["A *strong*, _emphasized_, and `coded` [link](https://example.com).\n"];
        yield 'sections' => ["# First heading\n\nBody.\n\n## Child heading\n\nMore body.\n"];
        yield 'code fence' => ["# Code\n\n```php\necho '<safe>';\n```\n"];
        yield 'duplicate heading ids' => ["# Same\n\n# Same\n"];
        yield 'tight list with continuation-shaped nested markers' => [
            "- first\n- second\n  - nested one\n  - nested *strong*\n",
        ];

        yield 'nested list after a blank' => ["- first\n- second\n\n  - child *strong*\n  - child _emphasis_\n"];
        yield 'nested list followed by a sibling' => ["- first\n\n  - child\n- second\n"];
        yield 'three list levels' => ["- first\n\n  - child\n\n    - grandchild\n"];
        yield 'table header' => ["| a | b |\n|---|---|\n| 1 | 2 |\n"];
        yield 'aligned table header' => ["| a | b | c |\n|:---|---:|:---:|\n| *one* | `two` | [three](https://example.com) |\n"];
        yield 'header without body' => ["| a | b |\n|---|---|\n"];
        yield 'nested list and aligned table in a section' => [
            "# Heading\n\n- first\n- second\n\n  - child\n  - another\n\n"
                . "| a | b |\n|:---|---:|\n| 1 | 2 |\n\n---\n",
        ];

        yield 'simple block quote' => ["> Quoted *strong* and [linked](https://example.com).\n"];
        yield 'plain-cell table' => [
            "| Name | Value |\n| --- | ---: |\n| alpha | `one` |\n",
        ];

        yield 'unresolved explicit reference and double strong' => [
            "Paragraph has **strong** and an [unresolved][missing] reference.\n",
        ];

        yield 'title-shaped line is prose' => [
            "[site]: https://example.com \"Example\"\n",
        ];
    }

    #[DataProvider('rejectedDocuments')]
    public function testAmbiguousOrUnsupportedDocumentsFallBack(string $source): void
    {
        self::assertNull((new BorrowedHtmlLayout())->render($source));
        self::assertSame(DjotConverter::create()->convert($source), (new DjotConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDocuments(): iterable
    {
        yield 'padded reference key' => ["[site]: https://example.com\n\n[a][ site ]\n"];
        yield 'repeated spaces in reference key' => ["[site x]: https://example.com\n\n[a][site  x]\n"];
        yield 'padded definition key' => ["[ site ]: https://example.com\n\n[a][site]\n"];
        yield 'repeated spaces in definition key' => ["[site  x]: https://example.com\n\n[a][site x]\n"];

        yield 'unclosed outer direct link label' => ["[[ok](https://example.com)\n"];
        yield 'unclosed outer reference label' => ["[[ok][missing]\n"];

        yield 'loose nested list' => ["- first\n\n  - child\n\n  - sibling\n"];
        yield 'nested continuation paragraph' => ["- first\n\n  - child\n  continuation\n"];
        yield 'nested attributes' => ["- first\n\n  {.blue}\n  - child\n"];
        yield 'over-indented nested marker' => ["- first\n\n   - child\n"];
        yield 'ragged aligned table' => ["| a | b |\n|---|---|\n| one |\n"];
        yield 'late table separator' => ["| a | b |\n| c | d |\n|---|---|\n"];
        yield 'repeated table separator' => ["| a | b |\n|---|---|\n|---|---|\n"];
        yield 'table spanning cell' => ["| a | b |\n|---|---|\n| ^ | c |\n"];

        yield 'lazy heading continuation' => ["# Heading\ncontinued\n"];
        yield 'unicode punctuation' => ["Grüße 😀\n"];
        yield 'attributes' => ["{.note}\nParagraph\n"];
        yield 'unsafe direct link' => ["[x](javascript:alert)\n"];
    }

    public function testOfficialSourcesAcceptedByTheFacadeMatchTheAstPipeline(): void
    {
        $layout = new BorrowedHtmlLayout();
        $converter = DjotConverter::create();
        $accepted = 0;
        foreach (OfficialTestSuiteTest::officialTestProvider() as $name => [$source]) {
            $result = $layout->render($source);
            if ($result !== null) {
                self::assertSame($converter->convert($source), $result['html'], $name);
                $accepted++;
            }
        }
        self::assertGreaterThan(0, $accepted);
    }

    public function testGeneratedListBoundariesMatchTheAstPipeline(): void
    {
        $layout = new BorrowedHtmlLayout();
        $converter = DjotConverter::create();
        $pieces = ["- sibling\n", "\n", "  - child\n", "    - grandchild\n", "text\n", "  text\n"];
        $accepted = 0;
        foreach ($pieces as $first) {
            foreach ($pieces as $second) {
                foreach ($pieces as $third) {
                    foreach ($pieces as $fourth) {
                        $source = "- first\n" . $first . $second . $third . $fourth;
                        $result = $layout->render($source);
                        if ($result !== null) {
                            self::assertSame($converter->convert($source), $result['html'], $source);
                            $accepted++;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(50, $accepted);
    }

    public function testNestedSpeculationAndSourceSizeRemainBounded(): void
    {
        $brackets = str_repeat('[', 4096) . "[ok](https://example.com)\n";
        self::assertNull((new BorrowedHtmlLayout())->render($brackets));
        self::assertSame(DjotConverter::create()->convert($brackets), (new DjotConverter())->convert($brackets));

        $source = "- first\n";
        for ($level = 1; $level <= 18; $level++) {
            $source .= "\n" . str_repeat('  ', $level) . "- child\n";
        }
        self::assertNull((new BorrowedHtmlLayout())->render($source));
        self::assertNull((new BorrowedHtmlLayout())->render(str_repeat("*text*\n\n", 11000)));
    }

    public function testCustomRendererNeverUsesTheDefaultFacade(): void
    {
        $source = "# Heading\n\nText.\n";
        $converter = new DjotConverter(renderer: new HtmlRenderer(), sections: false);

        self::assertSame(DjotConverter::create(renderer: new HtmlRenderer())->convert($source), $converter->convert($source));
    }
}
