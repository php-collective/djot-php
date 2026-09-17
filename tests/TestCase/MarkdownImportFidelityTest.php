<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\MarkdownToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

final class MarkdownImportFidelityTest extends TestCase
{
    public function testRawHtmlAndCharacterReferencesKeepTheirMeaning(): void
    {
        $source = (new MarkdownToDjot(trustedRawHtml: true))->convert('<span>x</span> &amp; y');

        self::assertSame('<p><span>x</span> &amp; y</p>' . "\n", (new DjotConverter())->convert($source));
    }

    public function testRawHtmlStaysInertByDefault(): void
    {
        $source = (new MarkdownToDjot())->convert('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', (new DjotConverter())->convert($source));
    }

    public function testFullAndImageReferencesAreNotRewrittenAsShortcuts(): void
    {
        $source = (new MarkdownToDjot())->convert("[text][r] ![r]\n\n[r]: /u");
        $html = (new DjotConverter())->convert($source);

        self::assertStringContainsString('<a href="/u">text</a>', $html);
        self::assertStringContainsString('<img alt="r" src="/u">', $html);
        self::assertStringNotContainsString('[]', $html);
    }

    public function testCodeFenceDefinitionDoesNotCreateAShortcutReference(): void
    {
        $source = (new MarkdownToDjot())->convert("```\n[r]: /u\n```\n\n[r]");

        self::assertStringNotContainsString('<a ', (new DjotConverter())->convert($source));
    }

    public function testDefinedShortcutReferenceRemainsALink(): void
    {
        $source = (new MarkdownToDjot())->convert("[r]\n\n[r]: /u");

        self::assertStringContainsString('<a href="/u">r</a>', (new DjotConverter())->convert($source));
    }

    public function testPointyDestinationLosesOnlyItsWrapper(): void
    {
        $source = (new MarkdownToDjot())->convert('[x](</u v>)');

        self::assertSame('<p><a href="/u%20v">x</a></p>' . "\n", (new DjotConverter())->convert($source));
    }

    public function testPointyDestinationKeepsAParenAndTitle(): void
    {
        $source = (new MarkdownToDjot())->convert('[x](<a(b)c> "title")');

        self::assertSame(
            '<p><a href="a%28b%29c" title="title">x</a></p>' . "\n",
            (new DjotConverter())->convert($source),
        );
    }
}
