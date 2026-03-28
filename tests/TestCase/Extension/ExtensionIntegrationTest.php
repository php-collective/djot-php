<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\HeadingReferenceExtension;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use Djot\Extension\TableOfContentsExtension;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for using multiple extensions together
 */
class ExtensionIntegrationTest extends TestCase
{
    public function testAllExtensionsTogether(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        // Register all extensions
        $converter
            ->addExtension(new AutolinkExtension())
            ->addExtension(new ExternalLinksExtension())
            ->addExtension(new MentionsExtension())
            ->addExtension(new HeadingPermalinksExtension())
            ->addExtension($tocExtension);

        $djot = <<<'DJOT'
# Welcome

Thanks @admin for setting this up!

## Getting Started

Visit https://example.com for documentation.

Check out [our guide](/guide) for more info.

## Contact

Email support@example.com or visit https://help.example.com for assistance.
DJOT;

        $html = $converter->convert($djot);

        // TOC should have 3 headings
        $this->assertCount(3, $tocExtension->getToc());

        // Mentions should be linked
        $this->assertStringContainsString('href="/users/view/admin"', $html);
        $this->assertStringContainsString('class="mention"', $html);

        // Auto-linked URLs should exist
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('href="https://help.example.com"', $html);

        // Auto-linked email should exist
        $this->assertStringContainsString('href="mailto:support@example.com"', $html);

        // External links should have target and rel
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);

        // Internal link should NOT have target="_blank"
        $this->assertStringContainsString('href="/guide"', $html);

        // Heading permalinks should be present
        $this->assertStringContainsString('class="permalink"', $html);
        $this->assertStringContainsString('¶', $html);
    }

    public function testAutolinkedUrlsGetExternalLinkAttributes(): void
    {
        $converter = new DjotConverter();

        // Order matters: AutolinkExtension first, then ExternalLinksExtension
        $converter
            ->addExtension(new AutolinkExtension())
            ->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('Visit https://example.com for info.');

        // The auto-linked URL should also get external link attributes
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testMentionsAndAutolinksInSameText(): void
    {
        $converter = new DjotConverter();
        $converter
            ->addExtension(new AutolinkExtension())
            ->addExtension(new MentionsExtension());

        $html = $converter->convert('@alice shared https://example.com with @bob');

        $this->assertStringContainsString('href="/users/view/alice"', $html);
        $this->assertStringContainsString('href="/users/view/bob"', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testTocWithHeadingPermalinks(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        // Registration order should not matter - HeadingIdTracker caches plain text
        $converter
            ->addExtension($tocExtension)
            ->addExtension(new HeadingPermalinksExtension());

        $djot = <<<'DJOT'
# Introduction

## Chapter One

## Chapter Two
DJOT;

        $html = $converter->convert($djot);
        $toc = $tocExtension->getToc();

        // TOC should capture heading text correctly
        $this->assertSame('Introduction', $toc[0]['text']);
        $this->assertSame('Chapter One', $toc[1]['text']);
        $this->assertSame('Chapter Two', $toc[2]['text']);

        // Both permalinks and TOC links should use same IDs
        $this->assertStringContainsString('href="#Introduction"', $html);
        $tocHtml = $tocExtension->getTocHtml();
        $this->assertStringContainsString('href="#Introduction"', $tocHtml);
    }

    public function testTocWithHeadingPermalinksReversedOrder(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        // Register HeadingPermalinks BEFORE TOC - should still work correctly
        $converter
            ->addExtension(new HeadingPermalinksExtension())
            ->addExtension($tocExtension);

        $djot = <<<'DJOT'
# Introduction

## Chapter One

## Chapter Two
DJOT;

        $html = $converter->convert($djot);
        $toc = $tocExtension->getToc();

        // TOC should capture clean heading text (not polluted with permalink symbol)
        $this->assertSame('Introduction', $toc[0]['text']);
        $this->assertSame('Chapter One', $toc[1]['text']);
        $this->assertSame('Chapter Two', $toc[2]['text']);

        // IDs should still match between TOC and HTML
        $tocHtml = $tocExtension->getTocHtml();
        $this->assertStringContainsString('href="#Introduction"', $tocHtml);
        $this->assertStringContainsString('href="#Introduction"', $html);
    }

    public function testTocAndPermalinksWithDuplicateHeadings(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        $converter
            ->addExtension($tocExtension)
            ->addExtension(new HeadingPermalinksExtension());

        $djot = <<<'DJOT'
# Introduction

Welcome text.

## Final Thoughts

First thoughts.

## Final Thoughts

Second thoughts.
DJOT;

        $html = $converter->convert($djot);
        $toc = $tocExtension->getToc();
        $tocHtml = $tocExtension->getTocHtml();

        // TOC entries should have deduplicated IDs
        $this->assertSame('Introduction', $toc[0]['id']);
        $this->assertSame('Final-Thoughts', $toc[1]['id']);
        $this->assertSame('Final-Thoughts-1', $toc[2]['id']);

        // Section IDs in HTML should match TOC IDs
        $this->assertStringContainsString('id="Introduction"', $html);
        $this->assertStringContainsString('id="Final-Thoughts"', $html);
        $this->assertStringContainsString('id="Final-Thoughts-1"', $html);

        // Permalink links should match section IDs
        $this->assertStringContainsString('href="#Final-Thoughts"', $html);
        $this->assertStringContainsString('href="#Final-Thoughts-1"', $html);

        // TOC links should also match section IDs
        $this->assertStringContainsString('href="#Final-Thoughts"', $tocHtml);
        $this->assertStringContainsString('href="#Final-Thoughts-1"', $tocHtml);
    }

    public function testHeadingReferencesShareIdsWithTocAndPermalinks(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        $converter
            ->addExtension(new HeadingReferenceExtension())
            ->addExtension($tocExtension)
            ->addExtension(new HeadingPermalinksExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Getting Started]].

## Getting Started
DJOT);

        $tocHtml = $tocExtension->getTocHtml();

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringContainsString('href="#Getting-Started"', $tocHtml);
    }

    public function testExternalLinksWithInternalHostsExcluded(): void
    {
        $converter = new DjotConverter();
        $converter
            ->addExtension(new AutolinkExtension())
            ->addExtension(new ExternalLinksExtension(
                internalHosts: ['example.com'],
            ));

        $html = $converter->convert('See https://example.com and https://other.com');

        // example.com should NOT have target="_blank"
        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/example\.com"[^>]*>/',
            $html,
        );

        // other.com SHOULD have target="_blank"
        $this->assertStringContainsString(
            'href="https://other.com" target="_blank"',
            $html,
        );
    }

    public function testExtensionsWithSafeMode(): void
    {
        $converter = new DjotConverter(safeMode: true);
        $converter
            ->addExtension(new AutolinkExtension())
            ->addExtension(new MentionsExtension());

        // Extensions should still work with safe mode enabled
        $html = $converter->convert('@user visited https://example.com');

        $this->assertStringContainsString('href="/users/view/user"', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testExtensionsWithProfiles(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();

        $converter
            ->addExtension($tocExtension)
            ->addExtension(new MentionsExtension());

        $djot = <<<'DJOT'
# Heading

Hello @world!
DJOT;

        $html = $converter->convert($djot);

        // Both extensions should work
        $this->assertTrue($tocExtension->hasToc());
        $this->assertStringContainsString('href="/users/view/world"', $html);
    }

    public function testRepeatedConversionsWithToc(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        // First conversion
        $converter->convert("# First Doc\n\n## Section A");
        $this->assertCount(2, $tocExtension->getToc());

        // Second conversion starts fresh for the new document
        $converter->convert('# Second Doc');
        $this->assertCount(1, $tocExtension->getToc());

        // Manual clear still works as before
        $tocExtension->clear();
        $converter->convert('# Third Doc');
        $this->assertCount(1, $tocExtension->getToc());
    }

    public function testCustomConfigurationCombination(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension(
            minLevel: 2,
            maxLevel: 3,
            listType: 'ol',
        );

        $converter
            ->addExtension(new ExternalLinksExtension(
                nofollow: true,
            ))
            ->addExtension(new HeadingPermalinksExtension(
                symbol: '#',
                cssClass: 'anchor',
            ))
            ->addExtension(new MentionsExtension(
                urlTemplate: '/profile/{username}',
                cssClass: 'user-link',
            ))
            ->addExtension($tocExtension);

        $djot = <<<'DJOT'
# Main Title

## Section One

Thanks @helper!

### Subsection

Visit [docs](https://docs.example.com).

# Another Title
DJOT;

        $html = $converter->convert($djot);
        $tocHtml = $tocExtension->getTocHtml();

        // TOC should only have h2 and h3 (not h1)
        $toc = $tocExtension->getToc();
        $this->assertCount(2, $toc);
        $this->assertSame(2, $toc[0]['level']);
        $this->assertSame(3, $toc[1]['level']);

        // TOC should use ordered list
        $this->assertStringContainsString('<ol>', $tocHtml);

        // Permalink should use # symbol
        $this->assertStringContainsString('>#<', $html);
        $this->assertStringContainsString('class="anchor"', $html);

        // Mention should use custom URL
        $this->assertStringContainsString('href="/profile/helper"', $html);
        $this->assertStringContainsString('class="user-link"', $html);

        // External link should have nofollow
        $this->assertStringContainsString('nofollow', $html);
    }
}
