<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Parser\BlockParser;
use Djot\Parser\UnitTableSpanIndex;
use Djot\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MixedTableSpanIndexTest extends TestCase
{
    public function testIndexKeepsOriginsAcrossHeaderReplacementAndExpiration(): void
    {
        $index = new UnitTableSpanIndex();
        $row = new TableRow();
        $first = new TableCell(rowspan: 3);
        $second = new TableCell();
        $row->appendChild($first);
        $row->appendChild($second);
        $index->addRow($row, 0);
        $this->assertSame($first, $index->findOrigin(0, 1));
        $this->assertSame($second, $index->findOrigin(1, 1));

        $header = new TableRow(true);
        $replacement = new TableCell(isHeader: true, rowspan: 3);
        $header->appendChild($replacement);
        $header->appendChild(new TableCell(isHeader: true));
        $index->replaceRow($header, 0);
        $this->assertSame($replacement, $index->findOrigin(0, 2));
        $this->assertNull($index->findOrigin(1, 2));
        $this->assertSame([0 => true], $index->overlappingColumns([$replacement], 2));
        $this->assertSame([0 => true], $index->overlappingColumns([$replacement], 2));
        $this->assertNull($index->findOrigin(0, 4));
    }

    public function testUnitSpansKeepLegacyOccupancyAndSourcePositions(): void
    {
        $sources = [
            "| a | b | c |\n| ^ | y | z |\n| y | ^ | z |\n",
            "| a | b | c |\n| ^ | y | z |\n| --- | --- | --- |\n| ^ | ^ | z |\n",
            "| a | b | c |\n| ^ | y |\n| ^ | ^ | z |\n",
            "| a | b | c |\n| ^ | y | z |\n| x | < | z |\n| ^ | y | ^ |\n",
        ];
        for ($mask = 0; $mask < 512; $mask++) {
            $source = '';
            for ($row = 0; $row < 3; $row++) {
                $cells = [];
                for ($col = 0; $col < 3; $col++) {
                    $cells[] = ($mask & (1 << ($row * 3 + $col))) !== 0 ? '^' : 'x';
                }
                $source .= '| ' . implode(' | ', $cells) . " |\n";
            }
            $sources[] = $source;
        }
        $seed = 19;
        for ($case = 0; $case < 400; $case++) {
            $source = '';
            for ($row = 0; $row < 8; $row++) {
                $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
                $width = 1 + $seed % 9;
                $cells = [];
                for ($col = 0; $col < $width; $col++) {
                    $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
                    $alphabet = $case < 200 ? ['x', 'y', '^', '^', '<'] : ['x', 'y', '^', '^', 'x'];
                    $cells[] = $alphabet[$seed % 5];
                }
                $source .= '| ' . implode(' | ', $cells) . " |\n";
                if ($seed % 5 === 0) {
                    $source .= '| ' . implode(' | ', array_fill(0, $width, '---')) . " |\n";
                    if ($seed % 10 === 0) {
                        $source .= '| ' . implode(' | ', array_fill(0, $width, '---')) . " |\n";
                    }
                }
            }
            $sources[] = $source;
        }
        $native = new DjotConverter(
            parser: new BlockParser(collectWarnings: true, trackSourceLines: true),
            warnings: true,
            sourceLines: true,
            roundTripMode: true,
        );
        $legacy = new DjotConverter(
            parser: new class (collectWarnings: true, trackSourceLines: true) extends BlockParser {
            },
            warnings: true,
            sourceLines: true,
            roundTripMode: true,
        );
        $markdown = new MarkdownRenderer();
        foreach ($sources as $source) {
            $expected = $legacy->parse($source);
            $actual = $native->parse($source);
            $this->assertSame(serialize($expected), serialize($actual), $source);
            $this->assertSame($legacy->convert($source), $native->convert($source), $source);
            $this->assertSame($legacy->getWarnings(), $native->getWarnings(), $source);
            $this->assertSame($markdown->render($expected), $markdown->render($actual), $source);
        }
    }
}
