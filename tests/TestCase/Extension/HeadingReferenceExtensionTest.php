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
