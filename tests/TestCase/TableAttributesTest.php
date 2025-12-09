<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table row and cell attributes.
 *
 * Row attributes: | cell |{.class}
 * Cell attributes: |{.class} cell |
 *
 * @see https://github.com/php-collective/djot-php/issues/18
 */
class TableAttributesTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testRowAttributesAfterFinalPipe(): void
    {
        $djot = <<<'DJOT'
| A | B |{.header}
|---|---|
| 1 | 2 |{.highlight}
| 3 | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(3, $rows);

        // Header row should have .header class
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertInstanceOf(TableRow::class, $headerRow);
        $this->assertTrue($headerRow->isHeader());
        $this->assertSame('header', $headerRow->getAttribute('class'));

        // Second row should have .highlight class
        /** @var \Djot\Node\Block\TableRow $row2 */
        $row2 = $rows[1];
        $this->assertSame('highlight', $row2->getAttribute('class'));

        // Third row should have no attributes
        /** @var \Djot\Node\Block\TableRow $row3 */
        $row3 = $rows[2];
        $this->assertNull($row3->getAttribute('class'));
    }

    public function testCellAttributesAfterOpeningPipe(): void
    {
        $djot = <<<'DJOT'
|{.name} Name |{.age} Age |
|---|---|
|{.emphasis} John | 30 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();

        // Check header cells
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $cells = $headerRow->getChildren();
        $this->assertCount(2, $cells);

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('name', $cell1->getAttribute('class'));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('age', $cell2->getAttribute('class'));

        // Check data row cell
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $dataCells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $dataCell1 */
        $dataCell1 = $dataCells[0];
        $this->assertSame('emphasis', $dataCell1->getAttribute('class'));

        /** @var \Djot\Node\Block\TableCell $dataCell2 */
        $dataCell2 = $dataCells[1];
        $this->assertNull($dataCell2->getAttribute('class'));
    }

    public function testCombinedRowAndCellAttributes(): void
    {
        $djot = <<<'DJOT'
|{.name} Name | Age |{.header-row}
|---|---|
|{.first} John | 30 |{.highlight}
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header row
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertSame('header-row', $headerRow->getAttribute('class'));

        $headerCells = $headerRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $nameCell */
        $nameCell = $headerCells[0];
        $this->assertSame('name', $nameCell->getAttribute('class'));

        // Data row
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $this->assertSame('highlight', $dataRow->getAttribute('class'));

        $dataCells = $dataRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $firstCell */
        $firstCell = $dataCells[0];
        $this->assertSame('first', $firstCell->getAttribute('class'));
    }

    public function testSeparatorRowAttributesIgnored(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|{.ignored}
| 1 | 2 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // The .ignored class should not appear anywhere
        foreach ($rows as $row) {
            $this->assertNotSame('ignored', $row->getAttribute('class'));
            foreach ($row->getChildren() as $cell) {
                $this->assertNotSame('ignored', $cell->getAttribute('class'));
            }
        }
    }

    public function testRowAttributesWithId(): void
    {
        $djot = <<<'DJOT'
| A | B |{#row1 .special}
|---|---|
| 1 | 2 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertSame('row1', $headerRow->getAttribute('id'));
        $this->assertSame('special', $headerRow->getAttribute('class'));
    }

    public function testCellAttributesWithMultipleClasses(): void
    {
        $djot = <<<'DJOT'
|{.primary .bold} Name | Age |
|---|---|
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $cells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $class = $cell1->getAttribute('class') ?? '';
        $this->assertStringContainsString('primary', $class);
        $this->assertStringContainsString('bold', $class);
    }

    public function testCellAttributesWithDataAttributes(): void
    {
        $djot = <<<'DJOT'
|{data-sort="asc"} Name | Age |
|---|---|
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $cells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('asc', $cell1->getAttribute('data-sort'));
    }

    public function testHtmlOutputWithRowAttributes(): void
    {
        $djot = <<<'DJOT'
| Name | Age |{.header}
|---|---|
| John | 30 |{.highlight}
DJOT;

        $html = $this->converter->convert($djot);

        $this->assertStringContainsString('<tr class="header">', $html);
        $this->assertStringContainsString('<tr class="highlight">', $html);
    }

    public function testHtmlOutputWithCellAttributes(): void
    {
        $djot = <<<'DJOT'
|{.name} Name | Age |
|:---|---:|
|{.emphasis} John | 30 |
DJOT;

        $html = $this->converter->convert($djot);

        // Header cell with class and alignment
        $this->assertStringContainsString('<th class="name" style="text-align: left;">Name</th>', $html);
        // Data cell with class and alignment
        $this->assertStringContainsString('<td class="emphasis" style="text-align: left;">John</td>', $html);
    }

    public function testAttributesPreservedWhenConvertingToHeader(): void
    {
        // Row attributes should be preserved when a row is converted to header
        // based on separator row detection
        $djot = <<<'DJOT'
|{.col1} A |{.col2} B |{.header-row}
|---|---|
| 1 | 2 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertTrue($headerRow->isHeader());
        $this->assertSame('header-row', $headerRow->getAttribute('class'));

        // Cell attributes should also be preserved
        $cells = $headerRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('col1', $cell1->getAttribute('class'));
        $this->assertTrue($cell1->isHeader());

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('col2', $cell2->getAttribute('class'));
    }

    public function testRowAttributesWithAlignment(): void
    {
        $djot = <<<'DJOT'
| Left | Center | Right |{.special}
|:-----|:------:|------:|
| a    | b      | c     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertSame('special', $headerRow->getAttribute('class'));

        $cells = $headerRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(TableCell::ALIGN_LEFT, $cell1->getAlignment());

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(TableCell::ALIGN_CENTER, $cell2->getAlignment());

        /** @var \Djot\Node\Block\TableCell $cell3 */
        $cell3 = $cells[2];
        $this->assertSame(TableCell::ALIGN_RIGHT, $cell3->getAlignment());
    }

    public function testTableWithOnlyRowAttributes(): void
    {
        // Table with no cell attributes, only row attributes
        $djot = <<<'DJOT'
| A | B |{.first}
| C | D |{.second}
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        $this->assertCount(2, $rows);

        /** @var \Djot\Node\Block\TableRow $row1 */
        $row1 = $rows[0];
        $this->assertSame('first', $row1->getAttribute('class'));

        /** @var \Djot\Node\Block\TableRow $row2 */
        $row2 = $rows[1];
        $this->assertSame('second', $row2->getAttribute('class'));
    }

    public function testTableWithOnlyCellAttributes(): void
    {
        // Table with only cell attributes, no row attributes
        $djot = <<<'DJOT'
|{.a} A |{.b} B |
|{.c} C |{.d} D |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \Djot\Node\Block\TableRow $row1 */
        $row1 = $rows[0];
        $cells1 = $row1->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell */
        $cell = $cells1[0];
        $this->assertSame('a', $cell->getAttribute('class'));
        $cell = $cells1[1];
        $this->assertSame('b', $cell->getAttribute('class'));

        /** @var \Djot\Node\Block\TableRow $row2 */
        $row2 = $rows[1];
        $cells2 = $row2->getChildren();

        $cell = $cells2[0];
        $this->assertSame('c', $cell->getAttribute('class'));
        $cell = $cells2[1];
        $this->assertSame('d', $cell->getAttribute('class'));
    }

    /**
     * Test that inline formatting is not mistaken for cell attributes.
     *
     * {=highlight=}, {+insert+}, {-delete-} etc should be rendered as inline
     * formatting, not stripped as attributes.
     */
    public function testInlineFormattingNotMistakenForAttributes(): void
    {
        $djot = <<<'DJOT'
| Syntax            | Result          |
| :---------------- | :-------------- |
| `{=Highlighted=}` | {=Highlighted=} |
| `{+Inserted+}`    | {+Inserted+}    |
| `{-Deleted-}`     | {-Deleted-}     |
DJOT;

        $html = $this->converter->convert($djot);

        // Inline formatting should render as proper HTML elements
        $this->assertStringContainsString('<mark>Highlighted</mark>', $html);
        $this->assertStringContainsString('<ins>Inserted</ins>', $html);
        $this->assertStringContainsString('<del>Deleted</del>', $html);
    }

    /**
     * Test that inline formatting with space after pipe is preserved.
     */
    public function testInlineFormattingWithLeadingSpace(): void
    {
        $djot = <<<'DJOT'
| Normal text | {=marked=} text |
|-------------|-----------------|
| {+added+}   | {-removed-}     |
DJOT;

        $html = $this->converter->convert($djot);

        $this->assertStringContainsString('<mark>marked</mark>', $html);
        $this->assertStringContainsString('<ins>added</ins>', $html);
        $this->assertStringContainsString('<del>removed</del>', $html);
    }

    /**
     * Test subscript and superscript in table cells.
     */
    public function testSubscriptSuperscriptInTableCells(): void
    {
        $djot = <<<'DJOT'
| Formula | Result   |
|---------|----------|
| E=mc^2^ | E=mc^2^  |
| H~2~O   | H~2~O    |
DJOT;

        $html = $this->converter->convert($djot);

        $this->assertStringContainsString('<sup>2</sup>', $html);
        $this->assertStringContainsString('<sub>2</sub>', $html);
    }

    /**
     * Test that actual cell attributes still work alongside inline formatting.
     */
    public function testCellAttributesWithInlineFormatting(): void
    {
        $djot = <<<'DJOT'
|{.highlight} {=marked=} text | normal |
|-----|------|
| a   | b    |
DJOT;

        $html = $this->converter->convert($djot);

        // Cell should have class attribute AND contain marked text
        $this->assertStringContainsString('class="highlight"', $html);
        $this->assertStringContainsString('<mark>marked</mark>', $html);
    }

    /**
     * Test braced quotes in table cells are not mistaken for attributes.
     */
    public function testBracedQuotesInTableCells(): void
    {
        $djot = <<<'DJOT'
| Quote type | Example |
|------------|---------|
| Single     | {''}    |
| Double     | {""}    |
DJOT;

        $html = $this->converter->convert($djot);

        // Should contain curly quotes, not be stripped
        $this->assertStringContainsString("\u{2018}", $html); // Left single quote
        $this->assertStringContainsString("\u{2019}", $html); // Right single quote
        $this->assertStringContainsString("\u{201C}", $html); // Left double quote
        $this->assertStringContainsString("\u{201D}", $html); // Right double quote
    }
}
