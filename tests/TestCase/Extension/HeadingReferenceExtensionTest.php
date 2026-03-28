<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\HeadingReferenceExtension;
use Djot\Extension\WikilinksExtension;
use LogicException;
use PHPUnit\Framework\TestCase;

class HeadingReferenceExtensionTest extends TestCase
{
    public function testBasicHeadingReference(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Getting Started]].

# Getting Started
DJOT);

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringContainsString('class="heading-ref"', $html);
        $this->assertStringContainsString('data-heading-ref="Getting Started"', $html);
    }

    public function testReferenceUsesExplicitHeadingId(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Installation]].

{#install}
## Installation
DJOT);

        $this->assertStringContainsString('href="#install"', $html);
    }

    public function testCustomDisplayText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Getting Started|the introduction]] for details.

# Getting Started
DJOT);

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringContainsString('>the introduction</a>', $html);
        $this->assertStringContainsString('data-heading-ref="Getting Started"', $html);
    }

    public function testCustomDisplayTextFallbackOnMissing(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[Missing|click here]].');

        // Falls back to literal syntax including display text
        $this->assertStringContainsString('[[Missing|click here]]', $html);
    }

    public function testDuplicateHeadingFallsBackToLiteralText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Installation]].

## Installation

## Installation
DJOT);

        $this->assertStringContainsString('[[Installation]]', $html);
        $this->assertStringNotContainsString('data-heading-ref="Installation"', $html);
    }

    public function testMissingHeadingFallsBackToLiteralText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[Missing Heading]].');

        $this->assertStringContainsString('[[Missing Heading]]', $html);
        $this->assertStringNotContainsString('data-heading-ref="Missing Heading"', $html);
    }

    public function testHashSyntaxIsNotConsumed(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[#installation]].');

        $this->assertStringContainsString('[[#installation]]', $html);
        $this->assertStringNotContainsString('href="#installation"', $html);
    }

    public function testWorksWithHeadingPermalinks(): void
    {
        $converter = new DjotConverter();
        $converter
            ->addExtension(new HeadingReferenceExtension())
            ->addExtension(new HeadingPermalinksExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Summary]].

## Summary
DJOT);

        $this->assertStringContainsString('href="#Summary"', $html);
        $this->assertStringContainsString('class="permalink"', $html);
    }

    public function testHeadingWithSmartQuotesMatchesStraightQuoteReference(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        // The parser converts straight quotes to smart quotes in heading text,
        // but reference targets keep straight quotes. The extension normalizes
        // quotes for matching so this should resolve correctly.
        $html = $converter->convert(<<<'DJOT'
See [[Say "Hello"]].

# Say "Hello"
DJOT);

        $this->assertStringContainsString('href="#Say-Hello"', $html);
        $this->assertStringNotContainsString('[[Say "Hello"]]', $html);
    }

    public function testHeadingWithFormattingMatchesPlainTextReference(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Say Hello]].

# Say _Hello_
DJOT);

        $this->assertStringContainsString('href="#Say-Hello"', $html);
    }

    public function testCustomCssClassWithMultipleSpaces(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension('foo  bar'));

        $html = $converter->convert(<<<'DJOT'
See [[Test]].

# Test
DJOT);

        // Multiple spaces should be handled, empty parts filtered out
        $this->assertStringContainsString('class="foo bar"', $html);
    }

    public function testHeadingWithNoTextIsIgnored(): void
    {
        // Headings with no plain text (like image-only headings) are skipped
        // and don't cause errors
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Real Heading]].

# ![image](logo.png)

# Real Heading
DJOT);

        // Reference resolves to the text heading, image heading is ignored
        $this->assertStringContainsString('href="#Real-Heading"', $html);
    }

    public function testUserAuthoredLinkWithMatchingPlaceholderIsNotRewritten(): void
    {
        $extension = new class ('heading-ref') extends HeadingReferenceExtension {
            protected function generatePlaceholderPrefix(): string
            {
                return 'collision-placeholder-';
            }
        };

        $converter = new DjotConverter();
        $converter->addExtension($extension);

        $html = $converter->convert(<<<'DJOT'
[outside](collision-placeholder-0__)

See [[Test]].

# Test
DJOT);

        $this->assertStringContainsString('<a href="collision-placeholder-0__">outside</a>', $html);
        $this->assertStringContainsString('href="#Test"', $html);
    }

    public function testConflictsWithWikilinksWhenAddedAfter(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HeadingReferenceExtension cannot be used together with WikilinksExtension');

        $converter->addExtension(new HeadingReferenceExtension());
    }

    public function testConflictsWithWikilinksWhenAddedBefore(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HeadingReferenceExtension cannot be used together with WikilinksExtension');

        $converter->addExtension(new WikilinksExtension());
    }
}
