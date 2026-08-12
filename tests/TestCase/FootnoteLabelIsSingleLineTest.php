<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FootnoteLabelIsSingleLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[DataProvider('lineEndings')]
    public function testAReferenceLabelDoesNotCrossALineEnding(string $ending): void
    {
        $source = "before[^two{$ending}words].\n";
        $converter = new DjotConverter(warnings: true);
        $document = $converter->parse($source);
        $types = array_map(
            static fn ($node): string => $node->getType(),
            $document->getChildren()[0]->getChildren(),
        );

        self::assertNotContains('footnote_ref', $types);
        self::assertContains('soft_break', $types);
        self::assertStringNotContainsString('doc-noteref', $converter->convert($source));
        self::assertSame([], array_filter(
            $converter->getWarnings(),
            static fn ($warning): bool => str_contains($warning->getMessage(), 'Undefined footnote'),
        ));
    }

    public function testAMultilineDefinitionMarkerDoesNotRegisterOrSwallowText(): void
    {
        $source = "see[^two words].\n\n[^two\nwords]: note.\n";
        $converter = new DjotConverter();

        $document = $converter->parse($source);
        self::assertNotContains('footnote', array_map(
            static fn ($node): string => $node->getType(),
            $document->getChildren(),
        ));
        self::assertStringContainsString("[^two\nwords]: note.", $converter->convert($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sameLineLabels(): iterable
    {
        yield 'space' => ['two words'];
        yield 'tab' => ["two\twords"];
    }

    #[DataProvider('sameLineLabels')]
    public function testSameLineWhitespaceStillResolves(string $label): void
    {
        $html = (new DjotConverter())->convert("see[^{$label}].\n\n[^{$label}]: note.\n");
        self::assertStringContainsString('doc-noteref', $html);
    }
}
