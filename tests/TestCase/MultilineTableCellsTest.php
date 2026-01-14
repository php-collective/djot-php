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
