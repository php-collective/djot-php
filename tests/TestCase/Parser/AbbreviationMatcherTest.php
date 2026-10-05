<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Parser\AbbreviationMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AbbreviationMatcherTest extends TestCase
{
    public function testMatchesTheLiteralRegexWithOverlapsAndUnicodeBoundaries(): void
    {
        $keys = ['a-b', 'a-b c', 'b c', 'é', 'é-x', '東京', 'x é', '_x', '12-34', '.a', 'a.', 'a a', 'a a a', '12', '-', '.', '…', '–', ' a', 'a ', "e\u{0301}"];
        usort($keys, static fn (string $a, string $b): int => strlen($b) - strlen($a));
        $pattern = '/\b(' . implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys)) . ')\b/u';
        $matcher = new AbbreviationMatcher($keys);
        $tokens = ['a-b', 'a-b c', 'é-x', 'é', '東京', 'x é', '_x', '12-34', '.a', 'a.', 'a a a', 'xyz', '12', '-', '…', ' a', 'a ', "e\u{0301}"];
        foreach ($tokens as $left) {
            foreach ($tokens as $right) {
                foreach ([' ', '.', '_', 'é', ''] as $separator) {
                    $text = $left . $separator . $right;
                    $this->assertSame(
                        preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY),
                        $matcher->split($text),
                        $text,
                    );
                }
            }
        }
    }

    public function testLargePunctuationDictionaryDoesNotExceedTheRegexCompileLimit(): void
    {
        $definitions = '';
        for ($i = 0; $i < 8192; $i++) {
            $definitions .= "*[term-$i]: expansion $i\n";
        }
        $converter = new DjotConverter();
        $this->assertSame(
            '<p><abbr title="expansion 8191">term-8191</abbr> unmatched</p>' . "\n",
            $converter->convert("term-8191 unmatched\n\n" . $definitions),
        );
        $this->assertSame('<p>term-8191</p>' . "\n", $converter->convert('term-8191'));
    }

    public function testLongestTermWinsWithoutEmittingEveryOverlappingMatch(): void
    {
        $keys = [];
        for ($i = 1; $i <= 256; $i++) {
            $keys[] = trim(str_repeat('a ', $i));
        }
        $text = trim(str_repeat('a ', 1024));
        $this->assertSame(
            [$keys[255], ' ', $keys[255], ' ', $keys[255], ' ', $keys[255]],
            (new AbbreviationMatcher($keys))->split($text),
        );
    }

    public function testOneLongPunctuationKeyUsesTheMatcher(): void
    {
        $key = str_repeat('word-', 14000) . 'last';
        $converter = new DjotConverter();
        $this->assertSame(
            '<p><abbr title="expanded">' . $key . '</abbr></p>' . "\n",
            $converter->convert($key . "\n\n*[" . $key . "]: expanded\n"),
        );
    }

    public function testMatcherBudgetPreservesTheLegacyPlainTextFallback(): void
    {
        $key = str_repeat('word-', 36000) . 'end';
        $converter = new DjotConverter();
        $this->assertSame(
            '<p>' . $key . '</p>' . "\n",
            $converter->convert($key . "\n\n*[" . $key . "]: expanded\n"),
        );
    }

    public function testInvalidUtf8KeysAreRejectedByTheInternalMatcher(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AbbreviationMatcher(["a-b\xFF"]);
    }

    public function testInvalidUtf8TextUsesThePlainTextFallback(): void
    {
        $this->assertFalse((new AbbreviationMatcher(['a-b']))->split("a-b\xFF"));
    }
}
