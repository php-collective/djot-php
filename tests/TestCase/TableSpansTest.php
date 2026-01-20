<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table colspan and rowspan support.
 *
 * Syntax:
 * - `<` in a cell means it's spanned from the cell to the left (colspan)
 * - `^` in a cell means it's spanned from the cell above (rowspan)
 *
 * @see https://github.com/jgm/djot/issues/368
 */
class TableSpansTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testBasicColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     |
|-------|-------|
| 1     | 2     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // Header row should have one cell with colspan=2
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(1, $headerCells);

        /** @var \Djot\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame(1, $headerCell->getRowspan());
    }

    public function testMultipleColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     | <     |
|-------|-------|-------|
| 1     | 2     | 3     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(1, $headerCells);

        /** @var \Djot\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(3, $headerCell->getColspan());
    }

    public function testColspanInMiddle(): void
    {
        $djot = <<<'DJOT'
| A | B     | <     | C |
|---|-------|-------|---|
| 1 | 2     | 3     | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(3, $headerCells);

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $headerCells[0];
        $this->assertSame(1, $cell1->getColspan());

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $headerCells[1];
        $this->assertSame(2, $cell2->getColspan());

        /** @var \Djot\Node\Block\TableCell $cell3 */
        $cell3 = $headerCells[2];
        $this->assertSame(1, $cell3->getColspan());
    }

    public function testBasicRowspan(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(3, $rows);

        // First data row should have cell with rowspan=2
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();
        $this->assertCount(2, $cells1);

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells1[0];
        $this->assertSame(2, $cell1->getRowspan());

        // Second data row should have only one cell (the ^ is not rendered)
        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $cells2 = $dataRow2->getChildren();
        $this->assertCount(1, $cells2);
    }

    public function testMultipleRowspan(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
| ^ | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(4, $rows);

        // First data row should have cell with rowspan=3
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells1[0];
        $this->assertSame(3, $cell1->getRowspan());
    }

    public function testCombinedRowspanAndColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     | B |
|-------|-------|---|
| 1     | 2     | 3 |
| ^     | 4     | 5 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header row: "A" with colspan=2, "B" with colspan=1
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(2, $headerCells);

        /** @var \Djot\Node\Block\TableCell $headerCell1 */
        $headerCell1 = $headerCells[0];
        $this->assertSame(2, $headerCell1->getColspan());

        // First data row: "1" with rowspan=2
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \Djot\Node\Block\TableCell $dataCell1 */
        $dataCell1 = $cells1[0];
        $this->assertSame(2, $dataCell1->getRowspan());
    }

    public function testColspanHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| Header | <      |
|--------|--------|
| A      | B      |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('Header', $html);
    }

    public function testRowspanHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('rowspan="2"', $html);
    }

    public function testColspanWithAlignment(): void
    {
        $djot = <<<'DJOT'
| Left   | <      |
|:-------|-------:|
| A      | B      |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame(TableCell::ALIGN_LEFT, $headerCell->getAlignment());
    }

    public function testColspanWithCellAttributes(): void
    {
        $djot = <<<'DJOT'
|{.highlight} Span | <     |
|------------------|-------|
| A                | B     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame('highlight', $headerCell->getAttribute('class'));
    }

    public function testRowspanWithRowAttributes(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |{.first}
| ^ | 3 |{.second}
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $this->assertSame('first', $dataRow1->getAttribute('class'));

        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertSame('second', $dataRow2->getAttribute('class'));
    }

    public function testNoSpanWithRegularContent(): void
    {
        // Cells with content other than just < or ^ should not be treated as markers
        $djot = <<<'DJOT'
| A   | B   |
|-----|-----|
| <x  | y<  |
| ^z  | z^  |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // All rows should have 2 cells
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $this->assertCount(2, $dataRow1->getChildren());

        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertCount(2, $dataRow2->getChildren());
    }

    public function testComplexSpanTable(): void
    {
        // Test a more complex table with multiple spans
        $djot = <<<'DJOT'
| Category | Item   | Price |
|----------|--------|-------|
| Fruits   | Apple  | $1    |
| ^        | Banana | $0.50 |
| ^        | Orange | $0.75 |
| Veggies  | Carrot | $0.30 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(5, $rows);

        // First data row: "Fruits" should have rowspan=3
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \Djot\Node\Block\TableCell $categoryCell */
        $categoryCell = $cells1[0];
        $this->assertSame(3, $categoryCell->getRowspan());

        // Rows 2 and 3 should have only 2 cells (^ marker not rendered)
        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertCount(2, $dataRow2->getChildren());

        /** @var \Djot\Node\Block\TableRow $dataRow3 */
        $dataRow3 = $rows[3];
        $this->assertCount(2, $dataRow3->getChildren());

        // Row 4 should have all 3 cells
        /** @var \Djot\Node\Block\TableRow $dataRow4 */
        $dataRow4 = $rows[4];
        $this->assertCount(3, $dataRow4->getChildren());
    }

    public function testColspanInDataRow(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| 1 | 2 | < |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(2, $cell2->getColspan());
    }

    public function testEscapedMarkers(): void
    {
        // Test that \^ and \< are not treated as markers
        $djot = <<<'DJOT'
| A  | B  |
|----|-----|
| \^ | \< |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        // Both cells should have rowspan=1, colspan=1
        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(1, $cell1->getRowspan());
        $this->assertSame(1, $cell1->getColspan());
    }

    public function testFullHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| A     | <     |
|-------|-------|
| 1     | 2     |
| ^     | 3     |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $expected = <<<'HTML'
<table>
<tr>
<th colspan="2">A</th>
</tr>
<tr>
<td rowspan="2">1</td>
<td>2</td>
</tr>
<tr>
<td>3</td>
</tr>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    public function testColspanAndRowspanSameCell(): void
    {
        // A cell can have both colspan and rowspan
        $djot = <<<'DJOT'
| A | < | B |
|---|---|---|
| 1 | 2 | 3 |
| ^ | 4 | 5 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header: "A" has colspan=2
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $headerA */
        $headerA = $headerCells[0];
        $this->assertSame(2, $headerA->getColspan());

        // Data row 1: "1" has rowspan=2
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $dataCells1 = $dataRow1->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $dataCells1[0];
        $this->assertSame(2, $cell1->getRowspan());
        $this->assertSame(1, $cell1->getColspan());
    }

    public function testMultipleColspanGroups(): void
    {
        // Multiple colspan groups in same row
        $djot = <<<'DJOT'
| A | < | B | < |
|---|---|---|---|
| 1 | 2 | 3 | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(2, $headerCells);

        /** @var \Djot\Node\Block\TableCell $cellA */
        $cellA = $headerCells[0];
        $this->assertSame(2, $cellA->getColspan());

        /** @var \Djot\Node\Block\TableCell $cellB */
        $cellB = $headerCells[1];
        $this->assertSame(2, $cellB->getColspan());
    }

    public function testRowspanAcrossMultipleColumns(): void
    {
        // Rowspan markers in multiple columns
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | ^ |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // First data row: both cells should have rowspan=2
        /** @var \Djot\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells = $dataRow1->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(2, $cell1->getRowspan());

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(2, $cell2->getRowspan());

        // Second data row should be empty (both cells are rowspan markers)
        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertCount(0, $dataRow2->getChildren());
    }

    public function testLiteralLessThanInCell(): void
    {
        // A cell with "a < b" comparison should not trigger colspan
        $djot = <<<'DJOT'
| Condition | Result |
|-----------|--------|
| a < b     | true   |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        // First cell should contain "a < b"
        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(1, $cell1->getColspan());
    }

    /**
     * Test that overlapping cells are removed when rowspan and colspan intersect.
     *
     * When a cell has both rowspan and colspan, cells in the "intersection"
     * area should be dropped to avoid invalid overlapping HTML.
     *
     * @see https://github.com/jgm/djot/issues/368 (Omikhleia's Note2)
     */
    public function testRowspanColspanIntersectionDropsOverlappingCells(): void
    {
        // Cell A has colspan=2, then ^ extends rowspan
        // Cell B at L2:H2 is inside A's span area and should be dropped
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | ^   | B   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have rowspan=2 colspan=2
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('colspan="2"', $html);

        // B should NOT be in the output (it's dropped)
        $this->assertStringNotContainsString('>B<', $html);

        // Verify table structure via DOM
        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Row L2 should only have L2 label cell, not B
        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $cells = $dataRow2->getChildren();
        $this->assertCount(1, $cells); // Only "L2" cell
    }

    public function testRowspanColspanIntersection3x3(): void
    {
        // A has colspan=3 and rowspan=3, all B/C/D/E cells should be dropped
        $djot = <<<'DJOT'
|     | H1  | H2  | H3  |
|-----|-----|-----|-----|
| L1  | A   | <   | <   |
| L2  | ^   | B   | C   |
| L3  | ^   | D   | E   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have rowspan=3 colspan=3
        $this->assertStringContainsString('rowspan="3"', $html);
        $this->assertStringContainsString('colspan="3"', $html);

        // B, C, D, E should NOT be in output
        $this->assertStringNotContainsString('>B<', $html);
        $this->assertStringNotContainsString('>C<', $html);
        $this->assertStringNotContainsString('>D<', $html);
        $this->assertStringNotContainsString('>E<', $html);
    }

    public function testRowspanColspanNoIntersectionKeepsCells(): void
    {
        // A has colspan=2 but no rowspan into L2
        // X and B should both be kept
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | X   | B   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have colspan=2 but no rowspan
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringNotContainsString('rowspan', $html);

        // Both X and B should be in output
        $this->assertStringContainsString('>X<', $html);
        $this->assertStringContainsString('>B<', $html);
    }

    public function testExplicitMarkersFor2x2Block(): void
    {
        // When all cells in the 2x2 area have markers, it creates a proper block
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | ^   | ^   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have rowspan=2 colspan=2
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('colspan="2"', $html);

        // Row L2 should only have L2 label, no other cells
        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $cells = $dataRow2->getChildren();
        $this->assertCount(1, $cells); // Only "L2" cell
    }
}
