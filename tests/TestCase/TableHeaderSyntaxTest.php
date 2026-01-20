<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Inline\Text;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Creole-style |= table header syntax.
 *
 * This syntax allows marking individual cells as headers without requiring
 * a separator row, enabling row headers and mixed header/data cells.
 */
class TableHeaderSyntaxTest extends TestCase
{
    private BlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
    }

    public function testBasicEqualsHeaderSyntax(): void
    {
        // Creole-style |= header syntax (no separator row needed)
        $doc = $this->parser->parse("|= Name |= Age |\n| Alice | 28 |");

        $this->assertCount(1, $doc->getChildren());
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // First row should be a header row
        $headerRow = $rows[0];
        $this->assertInstanceOf(TableRow::class, $headerRow);
        $this->assertTrue($headerRow->isHeader());

        // Header cells should be marked as headers
        $headerCells = $headerRow->getChildren();
        $this->assertCount(2, $headerCells);
        $this->assertInstanceOf(TableCell::class, $headerCells[0]);
        $this->assertTrue($headerCells[0]->isHeader());
        $this->assertTrue($headerCells[1]->isHeader());

        // Second row should be a data row
        $dataRow = $rows[1];
        $this->assertInstanceOf(TableRow::class, $dataRow);
        $this->assertFalse($dataRow->isHeader());
    }

    public function testEqualsHeaderAlignment(): void
    {
        // |=< left, |=> right, |=~ center
        $doc = $this->parser->parse("|=< Left |=> Right |=~ Center |\n| A | B | C |");

        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $headerRow = $table->getChildren()[0];
        $cells = $headerRow->getChildren();

        $this->assertSame(TableCell::ALIGN_LEFT, $cells[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_RIGHT, $cells[1]->getAlignment());
        $this->assertSame(TableCell::ALIGN_CENTER, $cells[2]->getAlignment());
    }

    public function testMixedHeaderAndDataCells(): void
    {
        // Mix of header and regular cells in a row
        $doc = $this->parser->parse("|= Header | Regular |\n| Data | Data |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Row with any header cell is marked as header row
        $firstRow = $rows[0];
        $this->assertTrue($firstRow->isHeader());

        $cells = $firstRow->getChildren();
        $this->assertTrue($cells[0]->isHeader());
        $this->assertFalse($cells[1]->isHeader());
    }

    public function testEqualsHeaderNoSeparatorNeeded(): void
    {
        // Unlike traditional tables, |= syntax doesn't need separator row
        $doc = $this->parser->parse("|= A |= B |\n| 1 | 2 |\n| 3 | 4 |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Should have 3 rows (1 header + 2 data), no separator consumed
        $this->assertCount(3, $rows);
        $this->assertTrue($rows[0]->isHeader());
        $this->assertFalse($rows[1]->isHeader());
        $this->assertFalse($rows[2]->isHeader());
    }

    public function testEqualsHeaderAlignmentPropagates(): void
    {
        // Header alignment should propagate to data cells when no separator row
        $doc = $this->parser->parse("|=> Right |=< Left |=~ Center |\n| A | B | C |\n| D | E | F |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header row alignments
        $headerCells = $rows[0]->getChildren();
        $this->assertSame(TableCell::ALIGN_RIGHT, $headerCells[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_LEFT, $headerCells[1]->getAlignment());
        $this->assertSame(TableCell::ALIGN_CENTER, $headerCells[2]->getAlignment());

        // Data rows should inherit column alignment from header
        $dataCells1 = $rows[1]->getChildren();
        $this->assertSame(TableCell::ALIGN_RIGHT, $dataCells1[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_LEFT, $dataCells1[1]->getAlignment());
        $this->assertSame(TableCell::ALIGN_CENTER, $dataCells1[2]->getAlignment());

        $dataCells2 = $rows[2]->getChildren();
        $this->assertSame(TableCell::ALIGN_RIGHT, $dataCells2[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_LEFT, $dataCells2[1]->getAlignment());
        $this->assertSame(TableCell::ALIGN_CENTER, $dataCells2[2]->getAlignment());
    }

    public function testSeparatorRowOverridesHeaderAlignment(): void
    {
        // Separator row alignment takes precedence over header |= alignment
        $doc = $this->parser->parse("|=> Right |=< Left |\n|:--------|------:|\n| A | B |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header cells get alignment from separator row, not from |= markers
        $headerCells = $rows[0]->getChildren();
        $this->assertSame(TableCell::ALIGN_LEFT, $headerCells[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_RIGHT, $headerCells[1]->getAlignment());

        // Data row also uses separator row alignment
        $dataCells = $rows[1]->getChildren();
        $this->assertSame(TableCell::ALIGN_LEFT, $dataCells[0]->getAlignment());
        $this->assertSame(TableCell::ALIGN_RIGHT, $dataCells[1]->getAlignment());
    }

    public function testEqualsWithSpaceIsLiteral(): void
    {
        // | = text | should be literal "= text", not header
        $doc = $this->parser->parse('|= Header | = literal |');

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $cells = $rows[0]->getChildren();

        $this->assertCount(2, $cells);
        // First cell is header (|= attached)
        $this->assertTrue($cells[0]->isHeader());
        // Second cell is NOT header (| = has space)
        $this->assertFalse($cells[1]->isHeader());

        // Second cell content should be "= literal"
        $cellContent = $this->getCellTextContent($cells[1]);
        $this->assertSame('= literal', $cellContent);
    }

    public function testAlignmentMarkersRequireAttachment(): void
    {
        // |=< attached should align, |= < with space should be literal
        $doc = $this->parser->parse('|=< Left |= < literal |');

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $cells = $rows[0]->getChildren();

        $this->assertCount(2, $cells);
        // First cell has left alignment (|=< attached)
        $this->assertSame(TableCell::ALIGN_LEFT, $cells[0]->getAlignment());
        // Second cell has default alignment (|= < has space before <)
        $this->assertSame(TableCell::ALIGN_DEFAULT, $cells[1]->getAlignment());

        // Second cell content should include the "<"
        $cellContent = $this->getCellTextContent($cells[1]);
        $this->assertSame('< literal', $cellContent);
    }

    public function testRowHeadersOnLeftSide(): void
    {
        // Use |= for row headers on the left side of a table
        $doc = $this->parser->parse("|= Category | Value |\n|= Apples | 10 |\n|= Oranges | 20 |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // All rows should be marked as header rows (they contain header cells)
        foreach ($rows as $row) {
            $this->assertTrue($row->isHeader());
            $cells = $row->getChildren();
            // First cell in each row is a header
            $this->assertTrue($cells[0]->isHeader());
            // Second cell is not a header
            $this->assertFalse($cells[1]->isHeader());
        }
    }

    public function testEqualsHeaderWithColspan(): void
    {
        // |= combined with colspan using <
        $doc = $this->parser->parse("|= Contact Info | < |\n| Email | Phone |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $headerCells = $rows[0]->getChildren();

        // "Contact Info" should be a header with colspan=2
        $this->assertCount(1, $headerCells);
        $this->assertTrue($headerCells[0]->isHeader());
        $this->assertSame(2, $headerCells[0]->getColspan());
    }

    public function testEqualsHeaderWithRowspan(): void
    {
        // |= combined with rowspan using ^
        $doc = $this->parser->parse("|= Category |= Item |\n| ^ | Apple |\n| ^ | Banana |");

        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // First row header should have rowspan=3
        $headerCells = $rows[0]->getChildren();
        $this->assertTrue($headerCells[0]->isHeader());
        $this->assertSame(3, $headerCells[0]->getRowspan());
    }

    public function testHtmlRendering(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("|= Name |= Age |\n| Alice | 28 |");

        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<th>Age</th>', $html);
        $this->assertStringContainsString('<td>Alice</td>', $html);
        $this->assertStringContainsString('<td>28</td>', $html);
    }

    public function testHtmlRenderingWithAlignment(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert('|=< Left |=> Right |=~ Center |');

        $this->assertStringContainsString('style="text-align: left;"', $html);
        $this->assertStringContainsString('style="text-align: right;"', $html);
        $this->assertStringContainsString('style="text-align: center;"', $html);
    }

    /**
     * Helper to extract text content from a cell.
     */
    private function getCellTextContent(TableCell $cell): string
    {
        $content = '';
        foreach ($cell->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }

        return $content;
    }
}
