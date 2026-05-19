<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Renderer\AsciiTransliterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Transliterator;

class AsciiTransliteratorTest extends TestCase
{
    /**
     * Covered ranges (Latin / Cyrillic / Greek / punctuation) must produce
     * byte-identical ASCII whether the ICU engine or the baked map is used —
     * the map is generated from the same ICU transform, so shared anchors
     * are stable regardless of whether ext-intl is installed.
     */
    #[DataProvider('deterministicCases')]
    public function testCoveredRangesAreEngineIndependent(string $input, string $expected): void
    {
        $withMap = new AsciiTransliterator(useIntl: false);
        $this->assertSame($expected, $withMap->transliterate($input), 'map fallback');

        if (class_exists(Transliterator::class)) {
            $withIntl = new AsciiTransliterator(useIntl: true);
            $this->assertSame($expected, $withIntl->transliterate($input), 'intl engine');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deterministicCases(): array
    {
        return [
            'plain ascii untouched' => ['Hello World', 'Hello World'],
            'german umlaut' => ['Über uns', 'Uber uns'],
            'sharp s' => ['Straße', 'Strasse'],
            'french accents' => ['café résumé', 'cafe resume'],
            'ligatures' => ['œuvre Æsir', 'oeuvre AEsir'],
            'smart quotes' => ['Bob’s “Guide”', "Bob's \"Guide\""],
            'dashes' => ['en–dash em—dash', 'en-dash em-dash'],
            'cyrillic' => ['Привет мир', 'Privet mir'],
            'nbsp' => ["a\u{00A0}b", 'a b'],
        ];
    }

    public function testEmptyStringStaysEmpty(): void
    {
        $this->assertSame('', (new AsciiTransliterator(useIntl: false))->transliterate(''));
    }

    public function testUnmappedScriptIsStrippedByMapFallback(): void
    {
        $map = new AsciiTransliterator(useIntl: false);

        // The baked map has no CJK entries (non-Latin script).
        $this->assertSame('', $map->transliterate('日本語'));

        // Greek romanization is context-sensitive in ICU (`αυ`→`au` but
        // `υ`→`y`), so the whole Greek block is excluded from the baked map
        // rather than baked half-right. Without intl it degrades to the
        // generated id, exactly like CJK; with intl it is still romanized.
        $this->assertSame('', $map->transliterate('Αυγή'));
        $this->assertSame('', $map->transliterate('Ελλάδα'));
    }

    /**
     * Word boundaries must survive the map fallback: unmapped non-ASCII
     * separators / punctuation (ideographic space, ideographic comma, …)
     * become a space so they later normalize to `-` instead of merging
     * adjacent ASCII words into one token.
     */
    public function testUnmappedSeparatorsKeepWordBoundaries(): void
    {
        $translit = new AsciiTransliterator(useIntl: false);

        $this->assertSame('foo bar', $translit->transliterate("foo\u{3000}bar"));
        $this->assertSame('foo bar', $translit->transliterate('foo、bar'));
        $this->assertSame('a b', $translit->transliterate('a→b'));
    }

    public function testIntlRomanizesUnmappedScript(): void
    {
        if (!class_exists(Transliterator::class)) {
            self::markTestSkipped('ext-intl not available');
        }

        foreach (['日本語', 'Αυγή'] as $input) {
            $result = (new AsciiTransliterator(useIntl: true))->transliterate($input);

            $this->assertNotSame('', $result, $input);
            $this->assertMatchesRegularExpression('/^[\x00-\x7F]+$/', $result, $input);
        }
    }

    public function testResultIsAlwaysPureAscii(): void
    {
        foreach (['🎉 party', 'mixed Übér 日本語 �яtest'] as $input) {
            foreach ([true, false] as $useIntl) {
                if ($useIntl && !class_exists(Transliterator::class)) {
                    continue;
                }
                $out = (new AsciiTransliterator(useIntl: $useIntl))->transliterate($input);
                $this->assertSame(1, preg_match('/^[\x00-\x7F]*$/', $out), $input);
            }
        }
    }

    /**
     * On an ext-intl build where ICU cannot create the transliterator,
     * the deterministic baked map must still be used for covered ranges
     * instead of silently stripping everything.
     */
    public function testFallsBackToMapWhenIcuUnavailable(): void
    {
        $translit = new class (useIntl: true) extends AsciiTransliterator {
            protected static function icu(): ?Transliterator
            {
                return null; // simulate Transliterator::create() returning null
            }
        };

        $this->assertSame('Uber-uns Privet', $translit->transliterate('Über-uns Привет'));
    }
}
