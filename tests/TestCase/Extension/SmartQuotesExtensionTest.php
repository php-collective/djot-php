<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\SmartQuotesExtension;
use PHPUnit\Framework\TestCase;

class SmartQuotesExtensionTest extends TestCase
{
    public function testGermanDoubleQuotes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert('"Hello"');

        $this->assertStringContainsString("\u{201E}Hello\u{201C}", $html);
    }

    public function testGermanSingleQuotes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert("'Hello'");

        $this->assertStringContainsString("\u{201A}Hello\u{2018}", $html);
    }

    public function testFrenchGuillemets(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'fr'));

        $html = $converter->convert('"Hello"');

        // French double: «\u{00A0}...\u{00A0}»
        $this->assertStringContainsString("\u{00AB}\u{00A0}Hello\u{00A0}\u{00BB}", $html);
    }

    public function testSwissGermanQuotes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de-CH'));

        $html = $converter->convert('"Hello"');

        $this->assertStringContainsString("\u{00AB}Hello\u{00BB}", $html);
    }

    public function testLocaleFallbackRegionToLanguage(): void
    {
        // de-AT is not in the table, should fall back to de
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de-AT'));

        $html = $converter->convert('"Hello"');

        $this->assertStringContainsString("\u{201E}Hello\u{201C}", $html);
    }

    public function testLocaleFallbackUnderscoreFormat(): void
    {
        // fr_FR with underscore should normalize to fr
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'fr_FR'));

        $html = $converter->convert('"Hello"');

        $this->assertStringContainsString("\u{00AB}\u{00A0}Hello\u{00A0}\u{00BB}", $html);
    }

    public function testLocaleFallbackUnknownToEnglish(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'xx'));

        $html = $converter->convert('"Hello"');

        // Falls back to English
        $this->assertStringContainsString("\u{201C}Hello\u{201D}", $html);
    }

    public function testApostropheStaysU2019WithAnyLocale(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert("don't");

        // Apostrophe should always be U+2019 regardless of locale
        $this->assertStringContainsString("don\u{2019}t", $html);
    }

    public function testApostropheBeforeDigitWithLocale(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'fr'));

        $html = $converter->convert("the '70s");

        // Apostrophe before digit is always U+2019
        $this->assertStringContainsString("\u{2019}70s", $html);
    }

    public function testExplicitCharacterOverrides(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(
            openDoubleQuote: "\u{00AB}",
            closeDoubleQuote: "\u{00BB}",
            openSingleQuote: "\u{2039}",
            closeSingleQuote: "\u{203A}",
        ));

        $html = $converter->convert('"Hello"');

        $this->assertStringContainsString("\u{00AB}Hello\u{00BB}", $html);
    }

    public function testExplicitOverridesWithLocale(): void
    {
        // Explicit characters should override locale
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(
            locale: 'de',
            openDoubleQuote: '[[',
            closeDoubleQuote: ']]',
        ));

        $html = $converter->convert('"Hello"');

        // Double quotes overridden, single quotes still from de locale
        $this->assertStringContainsString('[[Hello]]', $html);
    }

    public function testBracedDoubleQuotesWithLocale(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert('{""} test');

        // {""} produces open + close double quotes in German
        $this->assertStringContainsString("\u{201E}\u{201C}", $html);
    }

    public function testBracedSingleQuotesWithLocale(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert('{\'\'} test');

        // {''} produces open + close single quotes in German
        $this->assertStringContainsString("\u{201A}\u{2018}", $html);
    }

    public function testBracedApostropheAlwaysU2019(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));

        $html = $converter->convert("{" . "'" . "} test");

        // {'} is always U+2019 (apostrophe), even with non-English locale
        $this->assertStringContainsString("\u{2019}", $html);
    }

    public function testJapaneseCjkCornerBrackets(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'ja'));

        $html = $converter->convert('"Hello"');

        // Japanese: corner brackets
        $this->assertStringContainsString("\u{300C}Hello\u{300D}", $html);
    }

    public function testGetSupportedLocales(): void
    {
        $locales = SmartQuotesExtension::getSupportedLocales();

        $this->assertContains('en', $locales);
        $this->assertContains('de', $locales);
        $this->assertContains('fr', $locales);
        $this->assertContains('ja', $locales);
        $this->assertContains('de-CH', $locales);
        $this->assertCount(count(SmartQuotesExtension::LOCALE_QUOTES), $locales);
    }

    public function testIsLocaleSupported(): void
    {
        $this->assertTrue(SmartQuotesExtension::isLocaleSupported('en'));
        $this->assertTrue(SmartQuotesExtension::isLocaleSupported('de'));
        $this->assertTrue(SmartQuotesExtension::isLocaleSupported('de-CH'));

        // Fallback to language part
        $this->assertTrue(SmartQuotesExtension::isLocaleSupported('de-AT'));
        $this->assertTrue(SmartQuotesExtension::isLocaleSupported('fr_FR'));

        // Unknown
        $this->assertFalse(SmartQuotesExtension::isLocaleSupported('xx'));
        $this->assertFalse(SmartQuotesExtension::isLocaleSupported('zz-ZZ'));
    }

    public function testEnglishLocaleMatchesDefault(): void
    {
        $converterDefault = new DjotConverter();
        $converterEn = new DjotConverter();
        $converterEn->addExtension(new SmartQuotesExtension(locale: 'en'));

        $input = <<<'DJOT'
        "Hello," she said. 'It is a fine day.'

        He replied, "I don't think so."

        The '70s were wild.
        DJOT;

        $htmlDefault = $converterDefault->convert($input);
        $htmlEn = $converterEn->convert($input);

        $this->assertSame($htmlDefault, $htmlEn);
    }
}
