<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Transliterator;

/**
 * Transliterates arbitrary Unicode text to ASCII for use in heading IDs.
 *
 * Heading IDs become URL fragments that get copied around and re-detected by
 * auto-linkers in chat clients, mail and other docs. Non-ASCII fragments are
 * routinely truncated or percent-encoded inconsistently there, producing
 * broken deep links. Reducing IDs to ASCII keeps shared links robust.
 *
 * Two engines:
 *  - ICU `Transliterator` ("Any-Latin; Latin-ASCII") when ext-intl is
 *    available — also romanizes scripts the map does not cover (e.g. CJK);
 *  - a baked Unicode->ASCII map generated *from that same ICU transform*
 *    (see ascii_translit_map.php) as the fallback.
 *
 * Because the fallback is generated from ICU, the common European / Cyrillic
 * / Greek / punctuation output is byte-identical with or without intl, so
 * shared anchors stay stable across environments. Only scripts outside the
 * baked ranges differ: intl romanizes them, the map drops them (the caller's
 * empty-result handling then yields a stable generated id).
 */
class AsciiTransliterator
{
    protected static bool $icuResolved = false;

    protected static ?Transliterator $icu = null;

    /**
     * @var array<string, string>|null
     */
    protected static ?array $map = null;

    protected bool $useIntl;

    /**
     * @param bool|null $useIntl Force the engine; null auto-detects ext-intl.
     */
    public function __construct(?bool $useIntl = null)
    {
        $this->useIntl = $useIntl ?? class_exists(Transliterator::class);
    }

    public function transliterate(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $icu = $this->useIntl ? static::icu() : null;
        if ($icu !== null) {
            $converted = $icu->transliterate($text);
            if ($converted !== false) {
                $text = $converted;
            }
        } else {
            // No usable ICU (intl absent, or Transliterator::create()
            // returned null on a broken build) — use the deterministic
            // baked map rather than stripping covered characters.
            $text = strtr($text, static::map());
        }

        // Anything still non-ASCII is something neither ICU nor the map
        // resolved. Turn separators / punctuation / symbols into a space
        // first so word boundaries (e.g. the ideographic space U+3000 or
        // comma U+3001 between ASCII words) survive as `-` instead of
        // merging tokens; then drop the rest (letters of unromanizable
        // scripts) so the caller falls back to a stable generated id.
        $text = (string)preg_replace_callback(
            '/[^\x00-\x7F]+/',
            static fn (array $m): string => (string)preg_replace('/[\p{Z}\p{P}\p{S}]/u', ' ', $m[0]),
            $text,
        );

        return (string)preg_replace('/[^\x00-\x7F]+/', '', $text);
    }

    protected static function icu(): ?Transliterator
    {
        if (!static::$icuResolved) {
            static::$icuResolved = true;
            static::$icu = class_exists(Transliterator::class)
                ? Transliterator::create('Any-Latin; Latin-ASCII')
                : null;
        }

        return static::$icu;
    }

    /**
     * @return array<string, string>
     */
    protected static function map(): array
    {
        return static::$map ??= require __DIR__ . '/ascii_translit_map.php';
    }
}
