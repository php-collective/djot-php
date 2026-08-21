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
        yield 'plain paragraphs' => ["First paragraph.\ncontinues here.\n\nSecond paragraph.\n"];
        yield 'core inline' => ["A *strong*, _emphasized_, and `coded` [link](https://example.com).\n"];
        yield 'sections' => ["# First heading\n\nBody.\n\n## Child heading\n\nMore body.\n"];
        yield 'code fence' => ["# Code\n\n```php\necho '<safe>';\n```\n"];
        yield 'duplicate heading ids' => ["# Same\n\n# Same\n"];
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
        yield 'lazy heading continuation' => ["# Heading\ncontinued\n"];
        yield 'lists' => ["- one\n- two\n"];
        yield 'quotes' => ["> quote\n"];
        yield 'tables' => ["| a | b |\n|---|---|\n| 1 | 2 |\n"];
        yield 'unicode' => ["Grüße\n"];
        yield 'attributes' => ["{.note}\nParagraph\n"];
        yield 'unsafe direct link' => ["[x](javascript:alert)\n"];
    }

    public function testCustomRendererNeverUsesTheDefaultFacade(): void
    {
        $source = "# Heading\n\nText.\n";
        $converter = new DjotConverter(renderer: new HtmlRenderer(), sections: false);

        self::assertSame(DjotConverter::create(renderer: new HtmlRenderer())->convert($source), $converter->convert($source));
    }
}
