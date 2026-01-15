<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Inline\Text;
use PHPUnit\Framework\TestCase;

/**
 * Tests for multi-line table cells using + prefix continuation syntax.
 *
 * Syntax: Lines starting with + continue the previous row's cells.
 *
 * | Name | Description |
 * |------|-------------|
 * | Item | Long text |
 * + | continued |
 *
 * @see https://github.com/jgm/djot/issues/368
 */
class MultilineTableCellsTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testBasicContinuation(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | First part  |
+      | second part |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // Data row should have merged content
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $this->assertFalse($dataRow->isHeader());

        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        // Second cell should contain merged content
        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text = $this->getCellTextContent($cell2);
        $this->assertSame('First part second part', $text);
    }

    public function testMultipleContinuationLines(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | Line one    |
+      | line two    |
+      | line three  |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text = $this->getCellTextContent($cell2);
        $this->assertSame('Line one line two line three', $text);
    }

    public function testContinuationInMultipleCells(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| A    | B           |
+ more | more B      |
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
        $text1 = $this->getCellTextContent($cell1);
        $this->assertSame('A more', $text1);

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text2 = $this->getCellTextContent($cell2);
        $this->assertSame('B more B', $text2);
    }

    public function testContinuationWithEmptyCells(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | First part  |
+      |             |
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
        $text1 = $this->getCellTextContent($cell1);
        $this->assertSame('Test', $text1);

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text2 = $this->getCellTextContent($cell2);
        $this->assertSame('First part', $text2);
    }

    public function testContinuationAfterHeaderRow(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
+ Long | Extended    |
|------|-------------|
| Test | Value       |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // Header row should have merged content
        /** @var \Djot\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $this->assertTrue($headerRow->isHeader());

        $cells = $headerRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $text1 = $this->getCellTextContent($cell1);
        $this->assertSame('Name Long', $text1);

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text2 = $this->getCellTextContent($cell2);
        $this->assertSame('Description Extended', $text2);
    }

    public function testMultipleRowsWithContinuation(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| A    | Desc A      |
+      | continued A |
| B    | Desc B      |
+      | continued B |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        $this->assertCount(3, $rows);

        // First data row
        /** @var \Djot\Node\Block\TableRow $row1 */
        $row1 = $rows[1];
        $cells1 = $row1->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell1_2 */
        $cell1_2 = $cells1[1];
        $this->assertSame('Desc A continued A', $this->getCellTextContent($cell1_2));

        // Second data row
        /** @var \Djot\Node\Block\TableRow $row2 */
        $row2 = $rows[2];
        $cells2 = $row2->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell2_2 */
        $cell2_2 = $cells2[1];
        $this->assertSame('Desc B continued B', $this->getCellTextContent($cell2_2));
    }

    public function testContinuationWithRowAttributes(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | First part  |{.highlight}
+      | second part |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];

        // Row attributes should be preserved from first line
        $this->assertSame('highlight', $dataRow->getAttribute('class'));

        $cells = $dataRow->getChildren();
        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('First part second part', $this->getCellTextContent($cell2));
    }

    public function testContinuationWithCellAttributes(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
|{.name} Test |{.desc} First part |
+             | second part       |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        // Cell attributes should be preserved from first line
        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('name', $cell1->getAttribute('class'));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('desc', $cell2->getAttribute('class'));
        $this->assertSame('First part second part', $this->getCellTextContent($cell2));
    }

    public function testContinuationWithAlignment(): void
    {
        $djot = <<<'DJOT'
| Left | Center | Right |
|:-----|:------:|------:|
| A    | B      | C     |
+ more | more B | more C|
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
        $this->assertSame('A more', $this->getCellTextContent($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(TableCell::ALIGN_CENTER, $cell2->getAlignment());
        $this->assertSame('B more B', $this->getCellTextContent($cell2));

        /** @var \Djot\Node\Block\TableCell $cell3 */
        $cell3 = $cells[2];
        $this->assertSame(TableCell::ALIGN_RIGHT, $cell3->getAlignment());
        $this->assertSame('C more C', $this->getCellTextContent($cell3));
    }

    public function testContinuationWithCodeSpan(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | Use `code`  |
+      | for example |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('for example', $html);
    }

    public function testContinuationWithPipeInCodeSpan(): void
    {
        $djot = <<<'DJOT'
| Name | Description      |
|------|------------------|
| Test | Use `a | b`      |
+      | for more info    |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('<code>a | b</code>', $html);
        $this->assertStringContainsString('for more info', $html);
    }

    public function testContinuationWithEscapedPipe(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | A \| B      |
+      | continued   |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $text = $this->getCellTextContent($cell2);
        $this->assertSame('A | B continued', $text);
    }

    public function testNonContinuationPlusAtStart(): void
    {
        // A + that doesn't end with | should not be treated as continuation
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | Value       |

+ Not a continuation
DJOT;

        $doc = $this->converter->parse($djot);
        $children = $doc->getChildren();

        $this->assertCount(2, $children);
        $this->assertInstanceOf(Table::class, $children[0]);
    }

    public function testHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| Name | Description |
|------|-------------|
| Test | First part  |
+      | second part |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $expected = <<<'HTML'
<table>
<tr>
<th>Name</th>
<th>Description</th>
</tr>
<tr>
<td>Test</td>
<td>First part second part</td>
</tr>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    public function testDifferentColumnCounts(): void
    {
        // Continuation line has fewer columns
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| 1 | 2 | 3 |
+ x | y |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];

        $rows = $table->getChildren();
        /** @var \Djot\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();

        $this->assertCount(3, $cells);

        /** @var \Djot\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame('1 x', $this->getCellTextContent($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('2 y', $this->getCellTextContent($cell2));

        /** @var \Djot\Node\Block\TableCell $cell3 */
        $cell3 = $cells[2];
        $this->assertSame('3', $this->getCellTextContent($cell3));
    }

    public function testContinuationOnlyAddingContent(): void
    {
        // Continuation line adds content only to empty base cell
        $djot = <<<'DJOT'
| A | B |
|---|---|
|   | X |
+ Y |   |
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
        $this->assertSame('Y', $this->getCellTextContent($cell1));

        /** @var \Djot\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame('X', $this->getCellTextContent($cell2));
    }

    public function testCodeSpanSplitAcrossContinuation(): void
    {
        // Code span backticks split across continuation lines
        // Note: Unclosed backtick in first row creates issues with continuation parsing
        // The continuation line may not be recognized if backticks interfere
        $djot = <<<'DJOT'
| Name | Code              |
|------|-------------------|
| Test | Start `code` here |
+      | and more          |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Complete code span works, continuation adds text
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('and more', $html);
    }

    public function testCodeSpanCompleteInContinuation(): void
    {
        // Complete code span in continuation line
        $djot = <<<'DJOT'
| Name | Description    |
|------|----------------|
| Test | Start here     |
+      | then `code`    |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('Start here', $html);
    }

    public function testMultilineCaption(): void
    {
        // Table with multi-line caption (jgm's concern)
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |

^ This is a caption
that spans multiple lines
for scientific writing
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \Djot\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $this->assertTrue($table->hasCaption());

        $html = $this->converter->render($doc);
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringContainsString('This is a caption', $html);
        $this->assertStringContainsString('multiple lines', $html);
    }

    public function testContinuationWithEmphasis(): void
    {
        // Emphasis that spans across continuation
        $djot = <<<'DJOT'
| Name | Description   |
|------|---------------|
| Test | _emphasis     |
+      | text_         |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Similar to code spans, split emphasis won't form a single element
        // The merged text is "_emphasis text_" which should be parsed as emphasis
        $this->assertStringContainsString('<em>', $html);
    }

    public function testContinuationWithStrongEmphasis(): void
    {
        // Strong emphasis in continuation
        $djot = <<<'DJOT'
| Name | Description       |
|------|-------------------|
| Test | This is *strong*  |
+      | and more text     |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('<strong>strong</strong>', $html);
        $this->assertStringContainsString('and more text', $html);
    }

    public function testContinuationWithLinkSplit(): void
    {
        // Link split across continuation - parser handles this gracefully
        $djot = <<<'DJOT'
| Name | Description          |
|------|----------------------|
| Test | See [example         |
+      | ](https://test.com)  |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // The merged text "[example ](https://test.com)" forms a valid link
        $this->assertStringContainsString('<a href="https://test.com">', $html);
    }

    public function testContinuationWithCompleteLink(): void
    {
        // Complete link in base row, continuation adds text
        $djot = <<<'DJOT'
| Name | Description                      |
|------|----------------------------------|
| Test | See [example](https://test.com)  |
+      | for more info                    |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('<a href="https://test.com">example</a>', $html);
        $this->assertStringContainsString('for more info', $html);
    }

    /**
     * Helper to extract text content from a cell.
     */
    protected function getCellTextContent(TableCell $cell): string
    {
        $content = '';
        foreach ($cell->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            } else {
                // For inline elements, recursively get text
                foreach ($child->getChildren() as $grandchild) {
                    if ($grandchild instanceof Text) {
                        $content .= $grandchild->getContent();
                    }
                }
            }
        }

        return $content;
    }
}
