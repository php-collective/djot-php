<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineWarningSourcePositionTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function undefinedReferenceProvider(): array
    {
        return [
            'list item' => ["- [x][missing]\n", 1, 3],
            'block quote' => ["> [x][missing]\n", 1, 3],
            'footnote body' => ["[^n]: [x][missing]\n\nsee[^n]\n", 1, 7],
            'second paragraph line' => ["first line\nsecond [x][missing]\n", 2, 8],
            'nested inline' => ["- _[x][missing]_\n", 1, 4],
            'inside div' => ["x\n\n::: d\n| [x][missing] |\n:::\n", 4, 3],
        ];
    }

    #[DataProvider('undefinedReferenceProvider')]
    public function testUndefinedReferenceUsesAuthoredCoordinates(string $source, int $line, int $column): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert($source);

        $warning = $converter->getWarnings()[0];
        $this->assertSame("Undefined reference 'missing'", $warning->getMessage());
        $this->assertSame($line, $warning->getLine());
        $this->assertSame($column, $warning->getColumn());
    }

    public function testUndefinedFootnoteUsesAuthoredCoordinates(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("> before [^missing]\n");

        $warning = $converter->getWarnings()[0];
        $this->assertSame("Undefined footnote 'missing'", $warning->getMessage());
        $this->assertSame(1, $warning->getLine());
        $this->assertSame(10, $warning->getColumn());
    }

    public function testRepeatedTableCellTextUsesEachCellsColumn(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("| [x][missing] | [x][missing] |\n");

        $warnings = $converter->getWarnings();
        $this->assertCount(2, $warnings);
        $this->assertSame(3, $warnings[0]->getColumn());
        $this->assertSame(18, $warnings[1]->getColumn());
    }

    public function testEscapedPipeAndContainerPrefixStayInTableColumns(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("> | a\\|b [x][missing] | [x][missing] |\n");

        $warnings = $converter->getWarnings();
        $this->assertCount(2, $warnings);
        $this->assertSame(10, $warnings[0]->getColumn());
        $this->assertSame(25, $warnings[1]->getColumn());
    }

    public function testLiteralEarlierMarkerDoesNotClaimWarningColumn(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert('`[x][missing]` and [x][missing]');

        $warning = $converter->getWarnings()[0];
        $this->assertSame(20, $warning->getColumn());
    }
}
