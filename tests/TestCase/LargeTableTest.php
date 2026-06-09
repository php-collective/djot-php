<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableRow;
use PHPUnit\Framework\TestCase;

/**
 * Guards table parsing against the O(rows^2) regression where holding a
 * copy-on-write alias of the table's child array across appendChild() forced
 * PHP to copy the whole array on every row. The fix releases the alias before
 * the append so building a table stays linear. These tests assert correctness
 * at scale (no rows or cells dropped); the linearity itself is what keeps them
 * fast.
 */
class LargeTableTest extends TestCase
{
    private DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testLargeTableKeepsEveryRowAndCell(): void
    {
        $rows = 5000;
        $source = "| A | B | C |\n|---|---|---|\n";
        for ($r = 0; $r < $rows; $r++) {
            $source .= "| r{$r}c0 | r{$r}c1 | r{$r}c2 |\n";
        }

        $document = $this->converter->parse($source);

        $table = $document->getChildren()[0] ?? null;
        $this->assertInstanceOf(Table::class, $table);

        $tableRows = $table->getChildren();
        // Header row + every body row.
        $this->assertCount($rows + 1, $tableRows);

        foreach ($tableRows as $tableRow) {
            $this->assertInstanceOf(TableRow::class, $tableRow);
            $this->assertCount(3, $tableRow->getChildren());
        }
    }

    public function testLargeTableRendersAllCells(): void
    {
        $rows = 2000;
        $source = "| A | B |\n|---|---|\n";
        for ($r = 0; $r < $rows; $r++) {
            $source .= "| left{$r} | right{$r} |\n";
        }

        $html = $this->converter->convert($source);

        $this->assertStringContainsString('left0', $html);
        $this->assertStringContainsString('right' . ($rows - 1), $html);
        // 1 header + N body rows, each opening a <tr>.
        $this->assertSame($rows + 1, substr_count($html, '<tr'));
    }
}
