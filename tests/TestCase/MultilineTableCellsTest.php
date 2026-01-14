<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Inline\Text;
use Djot\Parser\Block\TableParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for multi-line table cell support via backslash continuation.
 *
 * Row-level continuation: trailing backslash after final pipe continues the entire row.
 * Syntax: | content | \
 *         | more |
 *
 * @see https://github.com/php-collective/djot-php/issues/26
 */
class MultilineTableCellsTest extends TestCase
{
    protected DjotConverter $converter;

    protected TableParser $tableParser;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->tableParser = new TableParser();
    }

    public function testIsRowContinuation(): void
    {
        // Valid continuation rows
        $this->assertTrue($this->tableParser->isRowContinuation('| A | B | \\'));
        $this->assertTrue($this->tableParser->isRowContinuation('| A | B |\\'));
        $this->assertTrue($this->tableParser->isRowContinuation('| content | \\  '));

        // Not continuation rows
        $this->assertFalse($this->tableParser->isRowContinuation('| A | B |'));
        $this->assertFalse($this->tableParser->isRowContinuation('| A | B \\|')); // Backslash before pipe, not after
        $this->assertFalse($this->tableParser->isRowContinuation('| A | B |\\\\')); // Escaped backslash
    }

    public function testStripContinuationMarker(): void
    {
        $this->assertSame('| A | B |', $this->tableParser->stripContinuationMarker('| A | B | \\'));
        $this->assertSame('| A | B |', $this->tableParser->stripContinuationMarker('| A | B |\\'));
        $this->assertSame('| A | B |', $this->tableParser->stripContinuationMarker('| A | B |'));
        // Escaped backslash should not be stripped
        $this->assertSame('| A | B |\\\\', $this->tableParser->stripContinuationMarker('| A | B |\\\\'));
    }

    public function testMergeCellContents(): void
    {
        // Basic merge
        $base = ['Hello', 'World'];
        $continuation = ['there', 'everyone'];
        $merged = $this->tableParser->mergeCellContents($base, $continuation);
        $this->assertSame(['Hello there', 'World everyone'], $merged);

        // Empty continuation cell
        $base = ['Hello', 'World'];
        $continuation = ['', 'everyone'];
        $merged = $this->tableParser->mergeCellContents($base, $continuation);
        $this->assertSame(['Hello', 'World everyone'], $merged);

        // Empty base cell
        $base = ['', 'World'];
        $continuation = ['Hello', ''];
        $merged = $this->tableParser->mergeCellContents($base, $continuation);
        $this->assertSame(['Hello', 'World'], $merged);
    }

    public function testBasicRowContinuation(): void
    {
        $djot = <<<'DJOT'
| Name | Description | \
|      | continued   |
|------|-------------|
| Test | value       |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // Header row should have merged content
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertTrue($headerRow->isHeader());

        $cells = $headerRow->getChildren();
        $this->assertCount(2, $cells);

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('Name', $this->getCellText($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('Description continued', $this->getCellText($cell2));
    }

    public function testMultipleContinuationLines(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Line 1 | First | \
|        | Second | \
|        | Third |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Data row should have triple-merged content
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('Line 1', $this->getCellText($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('First Second Third', $this->getCellText($cell2));
    }

    public function testContinuationWithRowAttributes(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Long | Description |{.highlight} \
|      | continued   |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $this->assertSame('highlight', $dataRow->getAttribute('class'));

        $cells = $dataRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('Description continued', $this->getCellText($cell2));
    }

    public function testContinuationWithCellAttributes(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
|{.first} Long |{.second} Description | \
|              | continued            |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('first', $cell1->getAttribute('class'));
        $this->assertSame('Long', $this->getCellText($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('second', $cell2->getAttribute('class'));
        $this->assertSame('Description continued', $this->getCellText($cell2));
    }

    public function testContinuationPreservesAlignment(): void
    {
        $djot = <<<'DJOT'
| Left | Center | Right |
|:-----|:------:|------:|
| A    | B      | C | \
|      |        | D |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(TableCell::ALIGN_LEFT, $cell1->getAlignment());

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(TableCell::ALIGN_CENTER, $cell2->getAlignment());

        /** @var \Djot\Node\Block\TableCell $cell3 */
        $cell3 = $cells[2];
        $this->assertSame(TableCell::ALIGN_RIGHT, $cell3->getAlignment());
        $this->assertSame('C D', $this->getCellText($cell3));
    }

    public function testEscapedBackslashNotContinuation(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Path | C:\\ |
| Next | Row  |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Should be 3 separate rows (header + 2 data rows)
        $this->assertCount(3, $rows);
    }

    public function testContinuationWithInlineFormatting(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Name | This is *bold* and | \
|      | _italic_ text      |
DJOT;

        $html = $this->converter->convert($djot);

        // The merged content should preserve inline formatting
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function testNonContinuationRowsUnaffected(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| 3 | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        $this->assertCount(3, $rows); // 1 header + 2 data rows

        /** @var \Djot\Node\Block\TableRow $row1 */
        $row1 = $rows[1];
        $cells1 = $row1->getChildren();
        $this->assertSame('1', $this->getCellText($cells1[0]));
        $this->assertSame('2', $this->getCellText($cells1[1]));

        /** @var \Djot\Node\Block\TableRow $row2 */
        $row2 = $rows[2];
        $cells2 = $row2->getChildren();
        $this->assertSame('3', $this->getCellText($cells2[0]));
        $this->assertSame('4', $this->getCellText($cells2[1]));
    }

    public function testHtmlOutputWithContinuation(): void
    {
        $djot = <<<'DJOT'
| Feature | Description |
|---------|-------------|
| Multi   | This is a very long | \
|         | description that spans multiple lines |
DJOT;

        $html = $this->converter->convert($djot);

        $this->assertStringContainsString('This is a very long description that spans multiple lines', $html);
    }

    // =========================================================================
    // Stress Tests (see issue #60)
    // =========================================================================

    /**
     * Stress test: Extended continuations with 10+ lines.
     */
    public function testExtendedContinuationTenPlusLines(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Start | Line 1 | \
|       | Line 2 | \
|       | Line 3 | \
|       | Line 4 | \
|       | Line 5 | \
|       | Line 6 | \
|       | Line 7 | \
|       | Line 8 | \
|       | Line 9 | \
|       | Line 10 | \
|       | Line 11 | \
|       | Line 12 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        // Should be 2 rows: 1 header + 1 merged data row
        $this->assertCount(2, $rows);

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('Start', $this->getCellText($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $expected = 'Line 1 Line 2 Line 3 Line 4 Line 5 Line 6 Line 7 Line 8 Line 9 Line 10 Line 11 Line 12';
        $this->assertSame($expected, $this->getCellText($cell2));
    }

    /**
     * Stress test: Combining cell attributes, row attributes, and continuation lines.
     */
    public function testAttributeMixingComplex(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
|{.cell-a #id-a} First |{.cell-b} Second |{.cell-c} Third |{.row-class #row-id} \
|                      | continued B     | continued C    |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];

        // Row attributes from first line
        $this->assertSame('row-class', $dataRow->getAttribute('class'));
        $this->assertSame('row-id', $dataRow->getAttribute('id'));

        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cellA */
        $cellA = $cells[0];
        $this->assertSame('cell-a', $cellA->getAttribute('class'));
        $this->assertSame('id-a', $cellA->getAttribute('id'));
        $this->assertSame('First', $this->getCellText($cellA));

        /** @var \Djot\Node\Block\TableCell $cellB */
        $cellB = $cells[1];
        $this->assertSame('cell-b', $cellB->getAttribute('class'));
        $this->assertSame('Second continued B', $this->getCellText($cellB));

        /** @var \Djot\Node\Block\TableCell $cellC */
        $cellC = $cells[2];
        $this->assertSame('cell-c', $cellC->getAttribute('class'));
        $this->assertSame('Third continued C', $this->getCellText($cellC));
    }

    /**
     * Stress test: Multiple attributes on same row with continuation.
     */
    public function testMultipleAttributesWithContinuation(): void
    {
        $djot = <<<'DJOT'
| X | Y |
|---|---|
|{.a #b data-x="1"} Content |{.c data-y="2"} More |{.row-style} \
|                           | extra               |{.ignored}
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        // Row attrs come from first line only
        $this->assertSame('row-style', $dataRow->getAttribute('class'));

        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('a', $cell1->getAttribute('class'));
        $this->assertSame('b', $cell1->getAttribute('id'));
        $this->assertSame('1', $cell1->getAttribute('data-x'));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('More extra', $this->getCellText($cell2));
    }

    /**
     * Stress test: Code spans containing backslash characters.
     */
    public function testCodeSpansWithBackslashes(): void
    {
        $djot = <<<'DJOT'
| Code | Description |
|------|-------------|
| `C:\Users\` | Windows path | \
|             | continues    |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Should have 2 rows (header + 1 merged data row)
        $this->assertCount(2, $rows);

        $html = $this->converter->convert($djot);
        $this->assertStringContainsString('<code>C:\Users\</code>', $html);
        $this->assertStringContainsString('Windows path continues', $html);
    }

    /**
     * Stress test: Code span with trailing backslash at end of cell.
     */
    public function testCodeSpanTrailingBackslash(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Path | `path\` | \
|      | next    |
DJOT;

        $html = $this->converter->convert($djot);
        $this->assertStringContainsString('<code>path\</code>', $html);
    }

    /**
     * Stress test: Multiple code spans with various escape sequences.
     */
    public function testMultipleCodeSpansEscapeSequences(): void
    {
        $djot = <<<'DJOT'
| Pattern | Meaning |
|---------|---------|
| `\\n` `\\t` `\\r` | Escape sequences | \
|                   | for whitespace   |
DJOT;

        $html = $this->converter->convert($djot);
        $this->assertStringContainsString('\\n', $html);
        $this->assertStringContainsString('\\t', $html);
        $this->assertStringContainsString('Escape sequences for whitespace', $html);
    }

    /**
     * Stress test: Empty/blank continuation cells.
     */
    public function testEmptyContinuationCells(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| First |   | Third | \
|       |   |       |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        $this->assertSame('First', $this->getCellText($cells[0]));
        $this->assertSame('', $this->getCellText($cells[1]));
        $this->assertSame('Third', $this->getCellText($cells[2]));
    }

    /**
     * Stress test: All cells empty in continuation line.
     */
    public function testAllEmptyContinuationLine(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Content | Here | \
|         |      |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        // Content should remain unchanged when continuation is empty
        $this->assertSame('Content', $this->getCellText($cells[0]));
        $this->assertSame('Here', $this->getCellText($cells[1]));
    }

    /**
     * Stress test: Continuation in first data row (boundary condition).
     */
    public function testContinuationInFirstDataRow(): void
    {
        $djot = <<<'DJOT'
| Header A | Header B |
|----------|----------|
| First    | Row      | \
|          | continued |
| Second   | Row      |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Should be 3 rows: 1 header + 2 data rows (first merged)
        $this->assertCount(3, $rows);

        /** @var \Djot\Node\Block\TableRow $firstData */
        $firstData = $rows[1];
        $cells = $firstData->getChildren();
        $this->assertSame('Row continued', $this->getCellText($cells[1]));

        /** @var \Djot\Node\Block\TableRow $secondData */
        $secondData = $rows[2];
        $cells2 = $secondData->getChildren();
        $this->assertSame('Row', $this->getCellText($cells2[1]));
    }

    /**
     * Stress test: Continuation in last row of table (boundary condition).
     */
    public function testContinuationInLastRow(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Row 1 | Data |
| Row 2 | Start | \
|       | End   |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Should be 3 rows: 1 header + 2 data rows
        $this->assertCount(3, $rows);

        /** @var \Djot\Node\Block\TableRow $lastRow */
        $lastRow = $rows[2];
        $cells = $lastRow->getChildren();
        $this->assertSame('Start End', $this->getCellText($cells[1]));
    }

    /**
     * Stress test: Header row with continuation (boundary condition).
     */
    public function testHeaderRowContinuation(): void
    {
        $djot = <<<'DJOT'
| Short | Very Long Header | \
|       | Name Here        |
|-------|------------------|
| A     | B                |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertTrue($headerRow->isHeader());

        $cells = $headerRow->getChildren();
        $this->assertSame('Short', $this->getCellText($cells[0]));
        $this->assertSame('Very Long Header Name Here', $this->getCellText($cells[1]));
    }

    /**
     * Stress test: Single row table with continuation (boundary condition).
     */
    public function testSingleRowTableContinuation(): void
    {
        $djot = <<<'DJOT'
| Only | Row | \
|      | Here |
|------|------|
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Just header row after separator
        $this->assertCount(1, $rows);

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $cells = $headerRow->getChildren();
        $this->assertSame('Row Here', $this->getCellText($cells[1]));
    }

    /**
     * Stress test: Large table with many continuations for performance.
     */
    public function testLargeTablePerformance(): void
    {
        // Generate a table with 50 rows, each having continuation
        $lines = ['| A | B | C |', '|---|---|---|'];

        for ($i = 1; $i <= 50; $i++) {
            $lines[] = "| Row {$i} | Content {$i} | Data {$i} | \\";
            $lines[] = "|        | cont {$i}    | more {$i} |";
        }

        $djot = implode("\n", $lines);

        $startTime = microtime(true);
        $doc = $this->converter->parse($djot);
        $parseTime = microtime(true) - $startTime;

        // Should parse reasonably fast (under 1 second)
        $this->assertLessThan(1.0, $parseTime, 'Parsing large table took too long');

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // 1 header + 50 data rows (continuations merged)
        $this->assertCount(51, $rows);

        // Spot check a few rows
        /** @var \Djot\Node\Block\TableRow $row25 */
        $row25 = $rows[25];
        $cells = $row25->getChildren();
        $this->assertSame('Content 25 cont 25', $this->getCellText($cells[1]));
    }

    /**
     * Stress test: Very wide table with many columns and continuation.
     */
    public function testWideTableWithContinuation(): void
    {
        $djot = <<<'DJOT'
| A | B | C | D | E | F | G | H |
|---|---|---|---|---|---|---|---|
| 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | \
| a | b | c | d | e | f | g | h |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        $this->assertCount(8, $cells);
        $this->assertSame('1 a', $this->getCellText($cells[0]));
        $this->assertSame('4 d', $this->getCellText($cells[3]));
        $this->assertSame('8 h', $this->getCellText($cells[7]));
    }

    /**
     * Stress test: Mixed regular rows and continuation rows.
     */
    public function testMixedRegularAndContinuationRows(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Normal | row |
| Multi | line | \
|       | one  |
| Another | normal |
| Also | multi | \
|      | line  | \
|      | two   |
| Final | row |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // 1 header + 5 data rows
        $this->assertCount(6, $rows);

        // Check each row
        $expectedB = ['row', 'line one', 'normal', 'multi line two', 'row'];
        for ($i = 1; $i <= 5; $i++) {
            /** @var \Djot\Node\Block\TableRow $row */
            $row = $rows[$i];
            $cells = $row->getChildren();
            /** @var \Djot\Node\Block\TableCell $cellB */
            $cellB = $cells[1];
            $this->assertSame($expectedB[$i - 1], $this->getCellText($cellB), "Row {$i} mismatch");
        }
    }

    /**
     * Stress test: Continuation terminated by non-table content.
     */
    public function testContinuationTerminatedByParagraph(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| Start | here | \

This is a paragraph that should end the table.
DJOT;

        $doc = $this->converter->parse($djot);
        $children = $doc->getChildren();

        // Should have a table and a paragraph
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Table::class, $children[0]);
    }

    /**
     * Extract text content from a table cell.
     */
    protected function getCellText(TableCell $cell): string
    {
        $text = '';
        foreach ($cell->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            }
        }

        return $text;
    }
}
