<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the single-quote opener/closer pairing produced by
 * InlineParser::buildSingleQuoteMatchCache(), so the O(n) stack-based
 * matching keeps the exact behavior of the former O(n^2) scan.
 */
class SingleQuoteMatchingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function quoteProvider(): array
    {
        return [
            // A balanced pair becomes an open + close curly quote.
            'matched pair' => ["'hello'", "\u{2018}hello\u{2019}"],
            // A flanking opener with no later closer stays an apostrophe.
            'lone opener is apostrophe' => ['say \'what', "say \u{2019}what"],
            // Nested pairs match innermost-first (inner pair closes before outer).
            'nested pairs' => ["'a 'b' c'", "\u{2018}a \u{2018}b\u{2019} c\u{2019}"],
            // Mid-word apostrophe is untouched; the following pair still matches.
            'apostrophe then pair' => ["it's a 'test'", "it\u{2019}s a \u{2018}test\u{2019}"],
        ];
    }

    #[DataProvider('quoteProvider')]
    public function testSingleQuotePairing(string $input, string $expected): void
    {
        $html = (new DjotConverter())->convert($input);

        $this->assertStringContainsString($expected, $html);
    }
}
