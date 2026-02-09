<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;

/**
 * Configures locale-specific smart quote characters
 *
 * By default, the parser uses English-style typographic quotes:
 * - Double: \u{201C}...\u{201D} (\u{201C}...\u{201D})
 * - Single: \u{2018}...\u{2019} (\u{2018}...\u{2019})
 *
 * This extension allows you to change them per locale (e.g., German \u{201E}...\u{201C},
 * French \u{00AB}...\u{00BB}) while keeping apostrophes as U+2019 regardless of locale.
 *
 * Usage with locale:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new SmartQuotesExtension(locale: 'de'));
 * ```
 *
 * Usage with explicit characters:
 * ```php
 * $converter->addExtension(new SmartQuotesExtension(
 *     openDoubleQuote: "\u{00AB}",
 *     closeDoubleQuote: "\u{00BB}",
 *     openSingleQuote: "\u{2039}",
 *     closeSingleQuote: "\u{203A}",
 * ));
 * ```
 */
class SmartQuotesExtension implements ExtensionInterface
{
    /**
     * Locale-to-quote-characters mapping
     *
     * Each entry: [openDouble, closeDouble, openSingle, closeSingle]
     *
     * @var array<string, array{string, string, string, string}>
     */
    public const LOCALE_QUOTES = [
        'en' => ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
        'de' => ["\u{201E}", "\u{201C}", "\u{201A}", "\u{2018}"],
        'de-CH' => ["\u{00AB}", "\u{00BB}", "\u{2039}", "\u{203A}"],
        'fr' => ["\u{00AB}\u{00A0}", "\u{00A0}\u{00BB}", "\u{2039}\u{00A0}", "\u{00A0}\u{203A}"],
        'pl' => ["\u{201E}", "\u{201D}", "\u{201A}", "\u{2019}"],
        'ru' => ["\u{00AB}", "\u{00BB}", "\u{201E}", "\u{201C}"],
        'ja' => ["\u{300C}", "\u{300D}", "\u{300E}", "\u{300F}"],
        'zh' => ["\u{300C}", "\u{300D}", "\u{300E}", "\u{300F}"],
        'sv' => ["\u{201D}", "\u{201D}", "\u{2019}", "\u{2019}"],
        'da' => ["\u{201E}", "\u{201C}", "\u{201A}", "\u{2018}"],
        'fi' => ["\u{201D}", "\u{201D}", "\u{2019}", "\u{2019}"],
        'cs' => ["\u{201E}", "\u{201C}", "\u{201A}", "\u{2018}"],
        'hu' => ["\u{201E}", "\u{201D}", "\u{201A}", "\u{2019}"],
        'it' => ["\u{00AB}", "\u{00BB}", "\u{201C}", "\u{201D}"],
        'es' => ["\u{00AB}", "\u{00BB}", "\u{201C}", "\u{201D}"],
        'pt' => ["\u{00AB}", "\u{00BB}", "\u{201C}", "\u{201D}"],
        'nl' => ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
        'nb' => ["\u{00AB}", "\u{00BB}", "\u{2018}", "\u{2019}"],
        'nn' => ["\u{00AB}", "\u{00BB}", "\u{2018}", "\u{2019}"],
        'uk' => ["\u{00AB}", "\u{00BB}", "\u{201E}", "\u{201C}"],
    ];

    protected string $openDoubleQuote;

    protected string $closeDoubleQuote;

    protected string $openSingleQuote;

    protected string $closeSingleQuote;

    /**
     * @param string|null $locale Locale code (e.g., 'de', 'fr', 'de-CH'). Explicit characters override locale.
     * @param string|null $openDoubleQuote Override opening double quote character
     * @param string|null $closeDoubleQuote Override closing double quote character
     * @param string|null $openSingleQuote Override opening single quote character
     * @param string|null $closeSingleQuote Override closing single quote character
     */
    public function __construct(
        ?string $locale = null,
        ?string $openDoubleQuote = null,
        ?string $closeDoubleQuote = null,
        ?string $openSingleQuote = null,
        ?string $closeSingleQuote = null,
    ) {
        $resolved = $this->resolveLocale($locale ?? 'en');

        $this->openDoubleQuote = $openDoubleQuote ?? $resolved[0];
        $this->closeDoubleQuote = $closeDoubleQuote ?? $resolved[1];
        $this->openSingleQuote = $openSingleQuote ?? $resolved[2];
        $this->closeSingleQuote = $closeSingleQuote ?? $resolved[3];
    }

    public function register(DjotConverter $converter): void
    {
        $converter->getParser()->getInlineParser()->setQuoteCharacters(
            $this->openDoubleQuote,
            $this->closeDoubleQuote,
            $this->openSingleQuote,
            $this->closeSingleQuote,
        );
    }

    /**
     * Get all supported locale codes
     *
     * @return array<string>
     */
    public static function getSupportedLocales(): array
    {
        return array_keys(self::LOCALE_QUOTES);
    }

    /**
     * Check if a locale is supported (exact match or language fallback)
     */
    public static function isLocaleSupported(string $locale): bool
    {
        $normalized = str_replace('_', '-', $locale);

        if (isset(self::LOCALE_QUOTES[$normalized])) {
            return true;
        }

        // Try language-only part
        $lang = explode('-', $normalized)[0];

        return isset(self::LOCALE_QUOTES[$lang]);
    }

    /**
     * Resolve a locale to quote characters with fallback
     *
     * Resolution order: exact match → language-only part → English defaults
     *
     * @return array{string, string, string, string}
     */
    protected function resolveLocale(string $locale): array
    {
        // Normalize underscore to hyphen (e.g., fr_FR → fr-FR)
        $normalized = str_replace('_', '-', $locale);

        // Exact match
        if (isset(self::LOCALE_QUOTES[$normalized])) {
            return self::LOCALE_QUOTES[$normalized];
        }

        // Language-only part (e.g., de-AT → de)
        $lang = explode('-', $normalized)[0];
        if (isset(self::LOCALE_QUOTES[$lang])) {
            return self::LOCALE_QUOTES[$lang];
        }

        // Fallback to English
        return self::LOCALE_QUOTES['en'];
    }
}
