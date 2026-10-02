<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Node\Block\Paragraph;
use Djot\Parser\Block\TableParser;
use Djot\Parser\BlockParser;
use Djot\Parser\InlineParser;
use PHPUnit\Framework\TestCase;

final class PerformanceScanTest extends TestCase
{
    public function testInlineScansPreserveOpaqueConstructsAndMismatchedRuns(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../Fixture/Parser/inline-scan-parity.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = DjotConverter::create();
        foreach ($rows as $row) {
            self::assertSame($row['html'], $converter->convert($row['source']), $row['source']);
        }
    }

    public function testMismatchedBacktickRunsRemainUnclosedCodeSpansAtParagraphEnd(): void
    {
        $converter = DjotConverter::create();
        foreach (['``a```b' => 'a```b', '``a```' => 'a```', '```a``' => 'a``'] as $source => $content) {
            self::assertSame('<p>before <code>' . $content . "</code></p>\n", $converter->convert('before ' . $source));
        }
    }

    public function testWarningIndexHandlesBackwardQueriesAndParserReuse(): void
    {
        $parser = new class (new class (collectWarnings: true) extends BlockParser {
            public function sourceWarningColumn(int $sourceLine, string $marker, int $fallback): int
            {
                return $fallback;
            }
        }) extends InlineParser {
            public function location(int $offset): array
            {
                return $this->warningLocation($offset, '');
            }
        };
        $parser->parse(new Paragraph(), "alpha\nbeta\ngamma", sourceLine: 20, sourceLineMap: [100, 103, 107]);
        foreach ([11 => [107, 1], 6 => [103, 1], 5 => [100, 6], 10 => [103, 5], 0 => [100, 1], 15 => [107, 5]] as $offset => [$line, $column]) {
            self::assertSame(['line' => $line, 'column' => $column], $parser->location($offset));
        }
        $parser->parse(new Paragraph(), 'new text', sourceLine: 3);
        self::assertSame(['line' => 3, 'column' => 5], $parser->location(4));
    }

    public function testRawTableSplittingPreservesEscapesAndClosingPipes(): void
    {
        $parser = new TableParser();
        foreach (
            [
                ['| a | b |', [' a ', ' b ']],
                ['| `a|b` |', [' `a', 'b` ']],
                ['| a\\|b | c', [' a|b ', ' c']],
                ['| a\\\\|b |', [' a\\|b ']],
                ['| α | β |{.row}', [' α ', ' β ']],
                ['plain', []],
                ['|', ['']],
            ] as [$source, $expected]
        ) {
            self::assertSame($expected, $parser->parseTableCellsRaw($source), $source);
        }
    }

    public function testTableSpanChecksPreserveExactBacktickRunLengths(): void
    {
        $parser = new TableParser();
        foreach (
            [
                ['| `a|b` |', false, true],
                ['| `a|b |', true, false],
                ['| ``a`|b`` |', false, true],
                ['| ``a```|b |', true, false],
                ['| ``a```|b`` |', false, true],
            ] as [$source, $unclosed, $endsWithPipe]
        ) {
            self::assertSame($unclosed, $parser->hasUnclosedCodeSpan($source), $source);
            self::assertSame($endsWithPipe, $parser->lineEndsWithPipeOutsideCodeSpan($source), $source);
        }
    }
}
