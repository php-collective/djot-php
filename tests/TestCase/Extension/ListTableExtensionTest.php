<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\ListTableExtension;
use PHPUnit\Framework\TestCase;

class ListTableExtensionTest extends TestCase
{
    /**
     * Convert with the list-table extension registered, trimmed for exact compare.
     */
    protected function render(string $djot): string
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ListTableExtension());

        return trim($converter->convert($djot));
    }

    public function testBasicTwoColumnWithHeaderRowAndCaption(): void
    {
        $djot = implode("\n", [
            '{caption="Quarterly results" header-rows=1}',
            '::: list-table',
            '- - Region',
            '  - Notes',
            '- - EMEA',
            '  - Strong quarter.',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <caption>Quarterly results</caption>',
            '  <thead><tr><th>Region</th><th>Notes</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td>Strong quarter.</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testMultiBlockCellStaysWrappedWhileSingleParagraphCollapses(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - EMEA',
            '  - Strong quarter.',
            '',
            '    Drivers:',
            '',
            '    - new logos',
            '    - renewals',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td><p>Strong quarter.</p>',
            '<p>Drivers:</p>',
            '<ul>',
            '<li>',
            'new logos',
            '</li>',
            '<li>',
            'renewals',
            '</li>',
            '</ul></td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testHeaderCols(): void
    {
        $djot = implode("\n", [
            '{header-cols=1}',
            '::: list-table',
            '- - Region',
            '  - Revenue',
            '- - EMEA',
            '  - 1.2M',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><th>Region</th><td>Revenue</td></tr>',
            '    <tr><th>EMEA</th><td>1.2M</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testHeaderRowsAndHeaderColsCombine(): void
    {
        $djot = implode("\n", [
            '{header-rows=1 header-cols=1}',
            '::: list-table',
            '- - Metric',
            '  - Q1',
            '  - Q2',
            '- - EMEA',
            '  - 1.0',
            '  - 1.2',
            ':::',
        ]);

        // The whole header row and the first column are all <th>.
        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th>Metric</th><th>Q1</th><th>Q2</th></tr></thead>',
            '  <tbody>',
            '    <tr><th>EMEA</th><td>1.0</td><td>1.2</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRaggedRowsArePadded(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - D',
            '  - E',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td><td>C</td></tr>',
            '    <tr><td>D</td><td>E</td><td></td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testNoCaption(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testInlineMarkupInCell(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - Use `flat` markup',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>Use <code>flat</code> markup</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testExtensionOffRendersDefaultDiv(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            ':::',
        ]);

        $converter = new DjotConverter();
        $html = trim($converter->convert($djot));

        $expected = implode("\n", [
            '<div class="list-table">',
            '<ul>',
            '<li>',
            '<ul>',
            '<li>',
            'A',
            '</li>',
            '<li>',
            'B',
            '</li>',
            '</ul>',
            '</li>',
            '</ul>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testOtherDivsAreNotClaimed(): void
    {
        $djot = implode("\n", [
            '::: note',
            'Hello.',
            ':::',
        ]);

        $html = $this->render($djot);

        $this->assertStringContainsString('<p>Hello.</p>', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testDivWithoutListDefersToDefault(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            'Just a paragraph, no list.',
            ':::',
        ]);

        $expected = implode("\n", [
            '<div class="list-table">',
            '<p>Just a paragraph, no list.</p>',
            '</div>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testStraySiblingContentDefersToDefaultAndIsNotDropped(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            'Intro paragraph.',
            '',
            '- - A',
            '  - B',
            '',
            'Trailing paragraph.',
            ':::',
        ]);

        $html = $this->render($djot);

        // The div is not claimed (extra siblings around the list); it degrades
        // to the default nested-list div so no content is lost.
        $this->assertStringStartsWith('<div class="list-table">', $html);
        $this->assertStringContainsString('<p>Intro paragraph.</p>', $html);
        $this->assertStringContainsString('<p>Trailing paragraph.</p>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testRowWithoutCellListDefersAndKeepsContent(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- Row label only',
            '- - A',
            '  - B',
            ':::',
        ]);

        $html = $this->render($djot);

        // A row authored with direct content (no inner cell list) means the
        // structure is not a clean table; defer to the default div so the
        // label is never dropped into an empty <tr>.
        $this->assertStringStartsWith('<div class="list-table">', $html);
        $this->assertStringContainsString('Row label only', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testRowspanWithCaret(): void
    {
        // EMEA spans down two rows: the second row's first cell is a lone `^`.
        $djot = implode("\n", [
            '::: list-table',
            '- - EMEA',
            '  - 10',
            '- - ^',
            '  - 14',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td rowspan="2">EMEA</td><td>10</td></tr>',
            '    <tr><td>14</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testColspanWithSingleLessThan(): void
    {
        // X spans two columns: the cell to its right is a lone `<`.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - X',
            '  - <',
            '  - Z',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td><td>C</td></tr>',
            '    <tr><td colspan="2">X</td><td>Z</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testColspanWithTwoLessThan(): void
    {
        // Total spans all three columns: two trailing `<` cells.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - Total',
            '  - <',
            '  - <',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td><td>C</td></tr>',
            '    <tr><td colspan="3">Total</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testSalesExampleWithRowspanAndColspan(): void
    {
        $djot = implode("\n", [
            '{caption="Sales" header-rows=1}',
            '::: list-table',
            '- - Region',
            '  - Q1',
            '  - Q2',
            '- - EMEA',
            '  - 10',
            '  - 12',
            '- - ^',
            '  - 14',
            '  - 16',
            '- - Total',
            '  - <',
            '  - <',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <caption>Sales</caption>',
            '  <thead><tr><th>Region</th><th>Q1</th><th>Q2</th></tr></thead>',
            '  <tbody>',
            '    <tr><td rowspan="2">EMEA</td><td>10</td><td>12</td></tr>',
            '    <tr><td>14</td><td>16</td></tr>',
            '    <tr><td colspan="3">Total</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testCombinedRowspanAndColspan(): void
    {
        // C spans two rows; X spans two columns in the row that also rowspans C.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - X',
            '  - <',
            '  - ^',
            '- - P',
            '  - Q',
            '  - R',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td><td rowspan="2">C</td></tr>',
            '    <tr><td colspan="2">X</td></tr>',
            '    <tr><td>P</td><td>Q</td><td>R</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testEscapedMarkersAreKeptLiteralNotSpans(): void
    {
        // An escaped `\^` / `\<` (and an attributed marker) is literal content,
        // never a span marker.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - \\^',
            '  - \\<',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '    <tr><td>^</td><td>&lt;</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testListTableSpanHtmlMatchesEquivalentPipeTable(): void
    {
        // The span resolution must produce the same rowspan/colspan markup the
        // native pipe table emits for the equivalent input.
        $listTable = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - X',
            '  - <',
            '  - ^',
            '- - P',
            '  - Q',
            '  - R',
            ':::',
        ]);

        $pipeTable = implode("\n", [
            '| A | B | C |',
            '|---|---|---|',
            '| X | < | ^ |',
            '| P | Q | R |',
        ]);

        $converter = new DjotConverter();
        $converter->addExtension(new ListTableExtension());
        $listHtml = trim($converter->convert($listTable));

        $pipeConverter = new DjotConverter();
        $pipeHtml = trim($pipeConverter->convert($pipeTable));

        // Normalize both to the bare cell markup (tag + span attributes + text),
        // dropping whitespace, thead/tbody grouping and the th/td distinction
        // that the list-table header conventions add on top.
        $normalize = static function (string $html): string {
            $html = preg_replace('/<\/?(table|thead|tbody|caption)[^>]*>/', '', $html) ?? $html;
            $html = preg_replace('/\s+/', '', $html) ?? $html;
            $html = str_replace(['<th', '</th>'], ['<td', '</td>'], $html);

            return $html;
        };

        $this->assertSame($normalize($pipeHtml), $normalize($listHtml));
    }

    public function testRowspanOverColspanReservesTheWholeRectangle(): void
    {
        // A colspan=2 cell that also spans two rows needs a `^` under each of
        // its columns; the result matches the equivalent pipe table.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - <',
            '  - C',
            '- - ^',
            '  - ^',
            '  - D',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td rowspan="2" colspan="2">A</td><td>C</td></tr>',
            '    <tr><td>D</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));

        // Same span markup as the equivalent pipe table.
        $pipe = implode("\n", [
            '| A | < | C |',
            '|---|---|---|',
            '| ^ | ^ | D |',
        ]);
        $pipeHtml = trim((new DjotConverter())->convert($pipe));
        $this->assertStringContainsString('<th rowspan="2" colspan="2">A</th>', $pipeHtml);
    }

    public function testRowspanAttachesToCellDirectlyAboveNotAcrossRaggedGaps(): void
    {
        // The middle row is short; its second column is padded with an empty
        // cell. A `^` in the next row must extend THAT padded cell, never jump
        // up to `B` two rows above - matching the equivalent pipe table.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - C',
            '- - X',
            '  - ^',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '    <tr><td>C</td><td rowspan="2"></td></tr>',
            '    <tr><td>X</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testConsecutiveLeadingColspanMarkersEachBecomeEmptyCell(): void
    {
        // A run of leading `<` with no cell to the left yields one empty cell
        // per marker (they never merge into each other), matching the pipe
        // table's leading-marker fallback.
        $djot = implode("\n", [
            '::: list-table',
            '- - <',
            '  - <',
            '  - A',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td></td><td></td><td>A</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testNoSpanMarkersIsUnchanged(): void
    {
        // A table with no span markers must render exactly as before.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - C',
            '  - D',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '    <tr><td>C</td><td>D</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testSiblingClassIsPreservedOnTable(): void
    {
        $djot = implode("\n", [
            '{.striped}',
            '::: list-table',
            '- - A',
            '  - B',
            ':::',
        ]);

        $html = $this->render($djot);

        $this->assertStringStartsWith('<table class="striped">', $html);
        $this->assertStringNotContainsString('list-table', $html);
    }
}
