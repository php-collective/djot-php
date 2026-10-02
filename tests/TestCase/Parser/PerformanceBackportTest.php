<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\Node\Block\Paragraph;
use Djot\Node\Document;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\Block\ListParser;
use Djot\Parser\BlockParser;
use Djot\Parser\InlineParser;
use Djot\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class PerformanceBackportTest extends TestCase
{
    public function testMarkerScreenPreservesThePatternCascade(): void
    {
        $screened = new ListParser();
        $patterns = new class extends ListParser {
        };
        foreach (['', '-', '*', '+', ':', '1.', '23)', '(1)', 'a.', 'Z)', '(a)', 'iv.', 'MM)', '(iv)', 'text', 'ab.', '1x', 'é', "\t1."] as $head) {
            foreach (['', ' text', ' ', '{.blue} item', ' [x] item', ' text*', "\titem"] as $tail) {
                $line = $head . $tail;
                self::assertSame($patterns->parseListItemMarker($line), $screened->parseListItemMarker($line), $line);
            }
        }
    }

    public function testFenceScreenPreservesWhitespaceAndFenceLengths(): void
    {
        $parser = new FencedBlockParser();
        foreach (['`', '~', ':'] as $char) {
            foreach (['', ' ', "\t", "\v", "\f", "\r", "\n"] as $padding) {
                foreach ([2, 3, 4, 7] as $length) {
                    foreach (['', ' ', ' text'] as $tail) {
                        $line = $padding . str_repeat($char, $length) . $tail;
                        $expected = preg_match('/^\s*' . preg_quote($char, '/') . '{3,}\s*$/', $line) === 1;
                        self::assertSame($expected, $parser->isCodeFenceCloser($line, $char, 3), $line);
                    }
                }
            }
        }
    }

    public function testBracketIndexPreservesThePreviousScanAndParserReuse(): void
    {
        $parser = new InlineParser(new BlockParser());
        $previous = new class (new BlockParser()) extends InlineParser {
            protected function findBalancedBracketEnd(string $text, int $open): ?int
            {
                $depth = 1;
                $end = $open + 1;
                $length = strlen($text);
                while ($end < $length && $depth > 0) {
                    if ($text[$end] === '[') {
                        $depth++;
                    } elseif ($text[$end] === ']') {
                        $depth--;
                    } elseif ($text[$end] === '\\' && $end + 1 < $length) {
                        $end++;
                    }
                    if ($depth > 0) {
                        $end++;
                    }
                }

                return $depth === 0 ? $end : null;
            }
        };
        $render = static function (InlineParser $parser, string $text): string {
            $document = new Document();
            $paragraph = new Paragraph();
            $parser->parse($paragraph, $text);
            $document->appendChild($paragraph);

            return (new HtmlRenderer())->render($document);
        };
        $pieces = ['[', ']', '\\[', '\\]', '`]`', '[ok](https://example.com)', '[span]{.blue}'];
        foreach ($pieces as $first) {
            foreach ($pieces as $second) {
                foreach ($pieces as $third) {
                    $source = $first . $second . $third . ' [last](https://example.com)';
                    self::assertSame($render($previous, $source), $render($parser, $source), $source);
                }
            }
        }
        $source = str_repeat('[', 4096) . '[ok](https://example.com)';
        self::assertSame(
            '<p>' . str_repeat('[', 4096) . '<a href="https://example.com">ok</a></p>' . "\n",
            $render($parser, $source),
        );
    }
}
