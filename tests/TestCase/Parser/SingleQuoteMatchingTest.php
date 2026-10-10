<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SingleQuoteMatchingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function quoteProvider(): array
    {
        return [
            'dash interruption' => ['"stop---" next', '“stop—” next'],
            'dash introduction' => ['--"hello"', '–“hello”'],
            'unicode dash close' => ['x—".', 'x—”.'],
            'elision' => ["say 'TIS enough", 'say ’TIS enough'],
            'quoted elision' => ["say 'em'", 'say ‘em’'],
            'contracted elision' => ["'tisn't so", '’tisn’t so'],
            'maximal word' => ["say 'tissue'", 'say ‘tissue’'],
            'initial unmatched opener' => ["'hello", '‘hello'],
            'double quote protects opener' => ['"\'hello', '“‘hello'],
            'markup opener' => ["say '*bold*", 'say ‘<strong>bold</strong>'],
            'nested inline state' => ["'a [say 'b](url) c'", '‘a <a href="url">say ’b</a> c’'],
            'nested demotion' => ["say [a 'word](url)", 'say <a href="url">a ’word</a>'],
            'digit apostrophe keeps span' => ["'a '70s b'", '‘a ’70s b’'],
            'unicode apostrophe keeps span' => ["'l'été 'word'", '‘l’été ’word’'],
            'block reset' => ["'open\n\nsay 'word", '<p>say ’word</p>'],
            'explicit opener survives' => ["say {'word", 'say ‘word'],
            'explicit closer ends span' => ["'a'} 'b'", '‘a’ ‘b’'],
            'escaped quote' => ["say \\'word", "say 'word"],
            'braced closer ends span' => ["'a{'} 'b'", '‘a’ ‘b’'],
            'braced pair ends span' => ["'a{''} 'b'", '‘a‘’ ‘b’'],
            'official doubled quotes' => ["''hi''", '‘‘hi’’'],
            // A balanced pair becomes an open + close curly quote.
            'matched pair' => ["'hello'", "\u{2018}hello\u{2019}"],
            // A flanking opener with no later closer stays an apostrophe.
            'lone opener is apostrophe' => ['say \'what', "say \u{2019}what"],
            // Single-quote spans do not nest.
            'nested pairs' => ["'a 'b' c'", "\u{2018}a \u{2019}b\u{2019} c\u{2019}"],
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
