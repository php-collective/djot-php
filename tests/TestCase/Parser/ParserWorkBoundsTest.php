<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Parser\Block\ListParser;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserWorkBoundsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function stackedMarkerProvider(): array
    {
        return [
            'bullet' => ['- '],
            'ordered' => ['1. '],
            'task' => ['- [ ] '],
            'quote and bullet' => ['> - '],
        ];
    }

    /**
     * Past the nesting cap, a longer line of stacked markers must not cost more marker parses.
     */
    #[DataProvider('stackedMarkerProvider')]
    public function testStackedMarkerWorkDoesNotGrowWithLineLength(string $marker): void
    {
        $short = $this->countMarkerParses(str_repeat($marker, 300) . "x\n");
        $long = $this->countMarkerParses(str_repeat($marker, 1200) . "x\n");

        $this->assertSame($short, $long);
    }

    public function testStackedMarkersPastTheCapStayLiteralText(): void
    {
        $html = (new DjotConverter())->convert(str_repeat('- ', 1000) . "x\n");

        $this->assertSame(200, substr_count($html, '<li>'));
        $this->assertStringContainsString(str_repeat('- ', 799) . 'x', $html);
    }

    public function testStackedMarkersBelowTheCapStillOpenAFence(): void
    {
        $html = (new DjotConverter())->convert(str_repeat('- ', 150) . "```\n" . str_repeat(' ', 300) . "code\n");

        $this->assertSame(150, substr_count($html, '<li>'));
        $this->assertStringContainsString("<pre><code>code\n</code></pre>", $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function smartQuoteWindowProvider(): array
    {
        return [
            'multibyte after a short word' => ["'ab€ x", "<p>‘ab€ x</p>\n"],
            'four-byte character next' => ["'😀' x", "<p>‘😀’ x</p>\n"],
            'elision word' => ["'tis so", "<p>’tis so</p>\n"],
            'elision word quoted' => ["x 'tis' y", "<p>x ‘tis’ y</p>\n"],
            'longer word sharing the prefix' => ["x 'emeralds' y", "<p>x ‘emeralds’ y</p>\n"],
            'seven letters' => ["x 'twasnts' y", "<p>x ‘twasnts’ y</p>\n"],
        ];
    }

    #[DataProvider('smartQuoteWindowProvider')]
    public function testSmartQuotesReadOnlyTheCharactersNextToTheQuote(string $input, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function longAttributeProvider(): array
    {
        $escaped = str_repeat('a\\"', 5000);

        return [
            'double-quoted escapes' => ['[x]{k="' . $escaped . '"}', '<span k="' . str_repeat('a&quot;', 5000) . '">x</span>'],
            'single-quoted escapes' => ["[x]{k='" . str_repeat("a\\'", 5000) . "'}", '<span k="' . str_repeat("a'", 5000) . '">x</span>'],
            'many class tokens' => ['[x]{' . str_repeat('.a ', 20000) . '}', '<span class="a">x</span>'],
        ];
    }

    #[DataProvider('longAttributeProvider')]
    public function testLongAttributeValuesStayAttributes(string $input, string $expected): void
    {
        $this->assertSame('<p>' . $expected . "</p>\n", (new DjotConverter())->convert($input));
    }

    private function countMarkerParses(string $input): int
    {
        $listParser = new class extends ListParser {
            public int $calls = 0;

            public function parseListItemMarker(string $line): ?array
            {
                $this->calls++;

                return parent::parseListItemMarker($line);
            }
        };
        $parser = new class ($listParser) extends BlockParser {
            public function __construct(ListParser $listParser)
            {
                parent::__construct();
                $this->listParser = $listParser;
            }
        };
        $parser->parse($input);

        return $listParser->calls;
    }
}
