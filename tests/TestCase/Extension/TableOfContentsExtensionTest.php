<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\TableOfContentsExtension;
use PHPUnit\Framework\TestCase;

class TableOfContentsExtensionTest extends TestCase
{
    public function testExtractHeadings(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $djot = <<<'DJOT'
# Introduction

Some text.

## Getting Started

More text.

### Installation

Steps here.

## Usage

Examples.

# Conclusion

Final words.
DJOT;

        $converter->convert($djot);

        $toc = $tocExtension->getToc();

        $this->assertCount(5, $toc);
        $this->assertSame('Introduction', $toc[0]['text']);
        $this->assertSame(1, $toc[0]['level']);
        $this->assertSame('Getting Started', $toc[1]['text']);
        $this->assertSame(2, $toc[1]['level']);
        $this->assertSame('Installation', $toc[2]['text']);
        $this->assertSame(3, $toc[2]['level']);
    }

    public function testGeneratesIds(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert('# Hello World');

        $toc = $tocExtension->getToc();

        $this->assertSame('Hello-World', $toc[0]['id']);
    }

    public function testMinMaxLevels(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension(minLevel: 2, maxLevel: 3);
        $converter->addExtension($tocExtension);

        $djot = <<<'DJOT'
# H1 - excluded

## H2 - included

### H3 - included

#### H4 - excluded
DJOT;

        $converter->convert($djot);

        $toc = $tocExtension->getToc();

        $this->assertCount(2, $toc);
        $this->assertSame('H2 - included', $toc[0]['text']);
        $this->assertSame('H3 - included', $toc[1]['text']);
    }

    public function testTocHtml(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert("# First\n\n## Second");

        $html = $tocExtension->getTocHtml();

        $this->assertStringContainsString('<nav class="toc">', $html);
        $this->assertStringContainsString('<a href="#First">First</a>', $html);
        $this->assertStringContainsString('<a href="#Second">Second</a>', $html);
    }

    public function testOrderedList(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension(listType: 'ol');
        $converter->addExtension($tocExtension);

        $converter->convert("# First\n\n# Second");

        $html = $tocExtension->getTocHtml();

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('</ol>', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension(cssClass: 'table-of-contents');
        $converter->addExtension($tocExtension);

        $converter->convert('# Test');

        $html = $tocExtension->getTocHtml();

        $this->assertStringContainsString('class="table-of-contents"', $html);
    }

    public function testHasToc(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert('Just a paragraph, no headings.');
        $this->assertFalse($tocExtension->hasToc());

        $converter->convert('# With a heading');
        $this->assertTrue($tocExtension->hasToc());
    }

    public function testClear(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert('# Heading');
        $this->assertTrue($tocExtension->hasToc());

        $tocExtension->clear();
        $this->assertFalse($tocExtension->hasToc());
    }

    public function testEmptyTocHtml(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert('No headings here.');

        $this->assertSame('', $tocExtension->getTocHtml());
    }

    public function testExplicitId(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $converter->convert("{#custom-id}\n# My Heading");

        $toc = $tocExtension->getToc();

        $this->assertSame('custom-id', $toc[0]['id']);
    }

    public function testNestedTocStructure(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension();
        $converter->addExtension($tocExtension);

        $djot = <<<'DJOT'
# Chapter 1

## Section 1.1

## Section 1.2

# Chapter 2

## Section 2.1
DJOT;

        $converter->convert($djot);

        $html = $tocExtension->getTocHtml();

        // Should have nested structure
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('Chapter 1', $html);
        $this->assertStringContainsString('Section 1.1', $html);
    }

    public function testPositionTop(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TableOfContentsExtension(position: 'top'));

        $html = $converter->convert("## First\n\nContent.\n\n## Second");

        // TOC should appear before content with separator
        $tocPos = strpos($html, '<nav class="toc">');
        $hrPos = strpos($html, '<hr>');
        $contentPos = strpos($html, '<section');
        $this->assertNotFalse($tocPos);
        $this->assertNotFalse($hrPos);
        $this->assertNotFalse($contentPos);
        $this->assertLessThan($hrPos, $tocPos);
        $this->assertLessThan($contentPos, $hrPos);
    }

    public function testPositionBottom(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TableOfContentsExtension(position: 'bottom'));

        $html = $converter->convert("## First\n\nContent.\n\n## Second");

        // TOC should appear after content with separator
        $tocPos = strpos($html, '<nav class="toc">');
        $hrPos = strrpos($html, '<hr>');
        $contentPos = strrpos($html, '</section>');
        $this->assertNotFalse($tocPos);
        $this->assertNotFalse($hrPos);
        $this->assertNotFalse($contentPos);
        $this->assertGreaterThan($contentPos, $hrPos);
        $this->assertGreaterThan($hrPos, $tocPos);
    }

    public function testCustomSeparator(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TableOfContentsExtension(
            position: 'top',
            separator: '<div class="toc-separator"></div>',
        ));

        $html = $converter->convert("## First\n\nContent.");

        $this->assertStringContainsString('<div class="toc-separator"></div>', $html);
        $this->assertStringNotContainsString('<hr>', $html);
    }

    public function testEmptySeparator(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TableOfContentsExtension(
            position: 'top',
            separator: '',
        ));

        $html = $converter->convert("## First\n\nContent.");

        $this->assertStringNotContainsString('<hr>', $html);
    }

    public function testPositionNullForManualPlacement(): void
    {
        $converter = new DjotConverter();
        $tocExtension = new TableOfContentsExtension(position: null);
        $converter->addExtension($tocExtension);

        $html = $converter->convert("## First\n\nContent.");

        // TOC should NOT be auto-inserted
        $this->assertStringNotContainsString('<nav class="toc">', $html);

        // But should be available via getTocHtml()
        $tocHtml = $tocExtension->getTocHtml();
        $this->assertStringContainsString('<nav class="toc">', $tocHtml);
    }
}
