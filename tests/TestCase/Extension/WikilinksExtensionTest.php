<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\WikilinksExtension;
use PHPUnit\Framework\TestCase;

class WikilinksExtensionTest extends TestCase
{
    public function testBasicWikilink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[Tigers]] for more info.');

        $this->assertStringContainsString('href="tigers"', $html);
        $this->assertStringContainsString('>Tigers</a>', $html);
        $this->assertStringContainsString('class="wikilink"', $html);
    }

    public function testWikilinkWithSpaces(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Read about [[Tiger Facts]] here.');

        $this->assertStringContainsString('href="tiger-facts"', $html);
        $this->assertStringContainsString('>Tiger Facts</a>', $html);
    }

    public function testWikilinkWithDisplayText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Learn about [[tigers|big cats]]');

        $this->assertStringContainsString('href="tigers"', $html);
        $this->assertStringContainsString('>big cats</a>', $html);
    }

    public function testWikilinkWithAnchor(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[page#section]]');

        $this->assertStringContainsString('href="page#section"', $html);
        $this->assertStringContainsString('>page</a>', $html);
    }

    public function testWikilinkWithAnchorAndDisplayText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Jump to [[page#intro|the introduction]]');

        $this->assertStringContainsString('href="page#intro"', $html);
        $this->assertStringContainsString('>the introduction</a>', $html);
    }

    public function testWikilinkWithPath(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[folder/subfolder/page]]');

        $this->assertStringContainsString('href="folder/subfolder/page"', $html);
    }

    public function testMultipleWikilinks(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Compare [[Lions]] and [[Tigers]].');

        $this->assertStringContainsString('href="lions"', $html);
        $this->assertStringContainsString('href="tigers"', $html);
    }

    public function testCustomUrlGenerator(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension(
            urlGenerator: fn (string $page) => '/wiki/' . strtolower(str_replace(' ', '_', $page)) . '.html',
        ));

        $html = $converter->convert('See [[Tiger Facts]]');

        $this->assertStringContainsString('href="/wiki/tiger_facts.html"', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension(
            cssClass: 'wiki-link internal',
        ));

        $html = $converter->convert('See [[Tigers]]');

        $this->assertStringContainsString('class="wiki-link internal"', $html);
    }

    public function testNewWindowOption(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension(
            newWindow: true,
        ));

        $html = $converter->convert('See [[External Page]]');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    public function testDataAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('[[My Page]]');

        $this->assertStringContainsString('data-wikilink="My Page"', $html);
    }

    public function testWikilinkAtStartOfText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('[[Home]] is the main page.');

        $this->assertStringContainsString('href="home"', $html);
    }

    public function testWikilinkAtEndOfText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Return to [[Home]]');

        $this->assertStringContainsString('href="home"', $html);
    }

    public function testWikilinkWithSpecialCharacters(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[C++ Programming]]');

        // Special chars should be stripped
        $this->assertStringContainsString('href="c-programming"', $html);
        $this->assertStringContainsString('>C++ Programming</a>', $html);
    }

    public function testWikilinkPreservesCase(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[MyPage]]');

        // URL should be lowercase
        $this->assertStringContainsString('href="mypage"', $html);
        // Display text preserves original case
        $this->assertStringContainsString('>MyPage</a>', $html);
    }

    public function testEmptyWikilinkNotParsed(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Empty [[]] here');

        // Empty wikilink should not be parsed - left as literal text
        $this->assertStringContainsString('[[]]', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function testWikilinkWithOnlyAnchor(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Jump to [[#section]]');

        $this->assertStringContainsString('href="#section"', $html);
        $this->assertStringContainsString('>#section</a>', $html);
    }

    public function testWikilinkInEmphasis(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('_Check out [[Tigers]]_');

        $this->assertStringContainsString('<em>', $html);
        $this->assertStringContainsString('href="tigers"', $html);
    }

    public function testWikilinkInStrong(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('*Read [[Important Page]]*');

        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('href="important-page"', $html);
    }

    public function testEscapedWikilinkNotParsed(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('Use \\[\\[double brackets\\]\\] for wikilinks.');

        // Escaped brackets should appear as literal text
        $this->assertStringContainsString('[[double brackets]]', $html);
        $this->assertStringNotContainsString('href="double', $html);
    }

    public function testObsidianStylePath(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension(
            urlGenerator: fn (string $page) => '/notes/' . rawurlencode($page) . '.md',
        ));

        $html = $converter->convert('See [[Daily Notes/2024-01-15]]');

        $this->assertStringContainsString('href="/notes/Daily%20Notes%2F2024-01-15.md"', $html);
    }

    public function testMediaWikiStyleUrl(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new WikilinksExtension(
            urlGenerator: fn (string $page) => '/wiki/' . str_replace(' ', '_', $page),
        ));

        $html = $converter->convert('See [[Main Page]]');

        $this->assertStringContainsString('href="/wiki/Main_Page"', $html);
    }
}
