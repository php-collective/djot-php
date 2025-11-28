<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Converter\MarkdownToDjot;
use PHPUnit\Framework\TestCase;

class MarkdownToDjotTest extends TestCase
{
    protected MarkdownToDjot $converter;

    protected function setUp(): void
    {
        $this->converter = new MarkdownToDjot();
    }

    public function testBoldDoubleAsterisk(): void
    {
        $markdown = 'This is **bold** text';
        $expected = 'This is *bold* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBoldDoubleUnderscore(): void
    {
        $markdown = 'This is __bold__ text';
        $expected = 'This is *bold* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testItalicSingleAsterisk(): void
    {
        $markdown = 'This is *italic* text';
        $expected = 'This is _italic_ text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testStrikethrough(): void
    {
        $markdown = 'This is ~~deleted~~ text';
        $expected = 'This is {-deleted-} text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testHighlight(): void
    {
        $markdown = 'This is ==highlighted== text';
        $expected = 'This is {=highlighted=} text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testSuperscript(): void
    {
        $markdown = 'E=mc^2^';
        $expected = 'E=mc{^2^}';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testSubscript(): void
    {
        $markdown = 'H~2~O';
        $expected = 'H{~2~}O';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testMixedFormatting(): void
    {
        $markdown = 'This has **bold** and *italic* and ~~strike~~';
        $expected = 'This has *bold* and _italic_ and {-strike-}';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeSpanPreserved(): void
    {
        $markdown = 'Use `**not bold**` in code';
        $expected = 'Use `**not bold**` in code';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeBlockPreserved(): void
    {
        $markdown = "Normal **bold**\n```\n**not bold**\n```\nNormal **bold**";
        $expected = "Normal *bold*\n```\n**not bold**\n```\nNormal *bold*";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testCodeBlockWithLanguage(): void
    {
        $markdown = "```php\n\$x = **value**;\n```";
        $expected = "```php\n\$x = **value**;\n```";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testTildeFencedCodeBlock(): void
    {
        $markdown = "~~~\n**not bold**\n~~~";
        $expected = "~~~\n**not bold**\n~~~";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testHeadingsUnchanged(): void
    {
        $markdown = "# Heading 1\n## Heading 2\n### Heading 3";
        $expected = "# Heading 1\n## Heading 2\n### Heading 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testLinksUnchanged(): void
    {
        $markdown = '[link text](https://example.com)';
        $expected = '[link text](https://example.com)';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testImagesUnchanged(): void
    {
        $markdown = '![alt text](image.png)';
        $expected = '![alt text](image.png)';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testListsUnchanged(): void
    {
        $markdown = "- item 1\n- item 2\n- item 3";
        $expected = "- item 1\n- item 2\n- item 3";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNumberedListsUnchanged(): void
    {
        $markdown = "1. first\n2. second\n3. third";
        $expected = "1. first\n2. second\n3. third";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBlockquotesUnchanged(): void
    {
        $markdown = "> This is a quote\n> with multiple lines";
        $expected = "> This is a quote\n> with multiple lines";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testThematicBreakUnchanged(): void
    {
        $markdown = "Before\n\n---\n\nAfter";
        $expected = "Before\n\n---\n\nAfter";

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testBoldAndItalicCombined(): void
    {
        $markdown = 'This is ***bold and italic*** text';
        // **bold** becomes *bold*, then remaining *italic* becomes _italic_
        $expected = 'This is *_bold and italic_* text';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testNestedFormatting(): void
    {
        $markdown = '**bold with *italic* inside**';
        $expected = '*bold with _italic_ inside*';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testDjotBracedSyntaxUnchanged(): void
    {
        // Braced Djot syntax should remain unchanged
        $djot = 'This is {-delete-} and {=highlight=} and {^super^} and {~sub~}';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testDjotSuperscriptUnchanged(): void
    {
        // Already in Djot format should not be double-wrapped
        $djot = 'E=mc{^2^}';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testDjotSubscriptUnchanged(): void
    {
        // Already in Djot format should not be double-wrapped
        $djot = 'H{~2~}O';

        $this->assertSame($djot, $this->converter->convert($djot));
    }

    public function testComplexDocument(): void
    {
        $markdown = <<<'MD'
# Welcome

This is a **bold** statement with *emphasis*.

## Features

- Item with ~~strikethrough~~
- Item with ==highlight==
- Item with `code`

```php
$bold = "**not converted**";
```

> A quote with **bold** text

[Link](https://example.com) and ![Image](img.png)
MD;

        $expected = <<<'DJOT'
# Welcome

This is a *bold* statement with _emphasis_.

## Features

- Item with {-strikethrough-}
- Item with {=highlight=}
- Item with `code`

```php
$bold = "**not converted**";
```

> A quote with *bold* text

[Link](https://example.com) and ![Image](img.png)
DJOT;

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testWhitespaceOnly(): void
    {
        $markdown = "   \n\n   ";
        $this->assertSame($markdown, $this->converter->convert($markdown));
    }

    public function testMultipleCodeSpans(): void
    {
        $markdown = 'Use `**bold**` and `*italic*` in code';
        $expected = 'Use `**bold**` and `*italic*` in code';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    public function testInlineCodeNextToFormatting(): void
    {
        $markdown = '**bold** then `code` then *italic*';
        $expected = '*bold* then `code` then _italic_';

        $this->assertSame($expected, $this->converter->convert($markdown));
    }
}
