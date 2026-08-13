<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A footnote label is normalized before lookup, and only the DEFINITION marker
 * is confined to one line.
 *
 * The two sides are deliberately asymmetric: a reference may be wrapped by an
 * editor and still bind, while the definition marker stays a single-line
 * construct the block parser can find without scanning ahead.
 */
class FootnoteLabelNormalizationTest extends TestCase
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
    public function testAWrappedReferenceBindsToTheOneLineDefinition(string $ending): void
    {
        $source = "before[^two{$ending}words].\n\n[^two words]: note.\n";
        $converter = new DjotConverter(warnings: true);

        $html = $converter->convert($source);

        self::assertStringContainsString('doc-noteref', $html);
        self::assertStringContainsString('note.', $html);
        self::assertSame([], array_filter(
            $converter->getWarnings(),
            static fn ($warning): bool => str_contains($warning->getMessage(), 'Undefined footnote'),
        ));
    }

    public function testAWrappedReferenceKeepsTheNormalizedLabelInTheAst(): void
    {
        $document = (new DjotConverter())->parse("see[^two\n   words].\n\n[^two words]: note.\n");

        $refs = [];
        foreach ($document->getChildren()[0]->getChildren() as $node) {
            if ($node->getType() === 'footnote_ref') {
                $refs[] = $node;
            }
        }

        self::assertCount(1, $refs);
        self::assertSame('two words', $refs[0]->getLabel());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whitespaceVariants(): iterable
    {
        yield 'a run of spaces in the reference' => ['two  words', 'two words'];
        yield 'a tab in the reference' => ["two\twords", 'two words'];
        yield 'padding around the reference' => [' two words ', 'two words'];
        yield 'a run of spaces in the definition' => ['two words', 'two  words'];
        yield 'a tab in the definition' => ['two words', "two\twords"];
    }

    #[DataProvider('whitespaceVariants')]
    public function testWhitespaceIsNormalizedOnBothSides(string $reference, string $definition): void
    {
        $html = (new DjotConverter())->convert("see[^{$reference}].\n\n[^{$definition}]: note.\n");

        self::assertStringContainsString('doc-noteref', $html);
        self::assertStringContainsString('note.', $html);
    }

    public function testADefinitionMarkerDoesNotCrossALineEnding(): void
    {
        $source = "see[^two words].\n\n[^two\nwords]: note.\n";
        $converter = new DjotConverter();

        $document = $converter->parse($source);

        self::assertNotContains('footnote', array_map(
            static fn ($node): string => $node->getType(),
            $document->getChildren(),
        ));

        // The line is a paragraph, so its own `[^two\nwords]` is an ordinary
        // (unresolved) reference and the `: note.` tail stays visible text.
        $html = $converter->convert($source);
        self::assertStringContainsString(': note.', $html);
    }

    public function testAnUndefinedWrappedReferenceWarnsUnderItsNormalizedLabel(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("see[^two\nwords].\n");

        $messages = array_map(
            static fn ($warning): string => $warning->getMessage(),
            $converter->getWarnings(),
        );

        self::assertNotSame([], array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'two words'),
        ));
    }
}
