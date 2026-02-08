<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Parser\BlockParser;
use Djot\Parser\InlineParser;
use PHPUnit\Framework\TestCase;
use function str_contains;

class InlineParserTest extends TestCase
{
    protected InlineParser $parser;

    protected BlockParser $blockParser;

    protected function setUp(): void
    {
        $this->blockParser = new BlockParser();
        $this->parser = new InlineParser($this->blockParser);
    }

    protected function parseInline(string $text): Paragraph
    {
        $para = new Paragraph();
        $this->parser->parse($para, $text);

        return $para;
    }

    protected function getFirstChild(Paragraph $para): mixed
    {
        $children = $para->getChildren();

        return $children[0] ?? null;
    }

    public function testParseText(): void
    {
        $para = $this->parseInline('Hello world');

        $this->assertCount(1, $para->getChildren());
        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('Hello world', $text->getContent());
    }

    public function testParseEmphasis(): void
    {
        $para = $this->parseInline('_emphasized_');

        $this->assertCount(1, $para->getChildren());
        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
    }

    public function testParseStrong(): void
    {
        $para = $this->parseInline('*strong*');

        $this->assertCount(1, $para->getChildren());
        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);
    }

    public function testParseCode(): void
    {
        $para = $this->parseInline('`code`');

        $this->assertCount(1, $para->getChildren());
        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('code', $code->getContent());
    }

    public function testParseCodeWithBackticks(): void
    {
        $para = $this->parseInline('`` `code` ``');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('`code`', $code->getContent());
    }

    public function testParseLink(): void
    {
        $para = $this->parseInline('[Example](https://example.com)');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://example.com', $link->getDestination());
    }

    public function testParseImage(): void
    {
        $para = $this->parseInline('![Alt text](image.jpg)');

        $image = $this->getFirstChild($para);
        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame('image.jpg', $image->getSource());
        $this->assertSame('Alt text', $image->getAlt());
    }

    public function testParseAutolink(): void
    {
        $para = $this->parseInline('<https://example.com>');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://example.com', $link->getDestination());
    }

    public function testParseEmailAutolink(): void
    {
        $para = $this->parseInline('<test@example.com>');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('mailto:test@example.com', $link->getDestination());
    }

    public function testParseSuperscript(): void
    {
        $para = $this->parseInline('x^2^');

        $children = $para->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Superscript::class, $children[1]);
    }

    public function testParseSubscript(): void
    {
        $para = $this->parseInline('H~2~O');

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(Subscript::class, $children[1]);
    }

    public function testParseSymbol(): void
    {
        $para = $this->parseInline(':smile:');

        $symbol = $this->getFirstChild($para);
        $this->assertInstanceOf(Symbol::class, $symbol);
        $this->assertSame('smile', $symbol->getName());
    }

    public function testParseMath(): void
    {
        $para = $this->parseInline('$`x^2`');

        $math = $this->getFirstChild($para);
        $this->assertInstanceOf(Math::class, $math);
        $this->assertSame('x^2', $math->getContent());
        $this->assertFalse($math->isDisplay());
    }

    public function testParseDisplayMath(): void
    {
        $para = $this->parseInline('$$`x^2`');

        $math = $this->getFirstChild($para);
        $this->assertInstanceOf(Math::class, $math);
        $this->assertTrue($math->isDisplay());
    }

    public function testParseSoftBreak(): void
    {
        $para = $this->parseInline("Line 1\nLine 2");

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(SoftBreak::class, $children[1]);
    }

    public function testParseHardBreak(): void
    {
        $para = $this->parseInline("Line 1\\\nLine 2");

        $children = $para->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(HardBreak::class, $children[1]);
    }

    public function testParseEscape(): void
    {
        $para = $this->parseInline('\*not strong\*');

        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame('*not strong*', $text->getContent());
    }

    public function testParseInlineAttributes(): void
    {
        $para = $this->parseInline('[styled]{.highlight}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'highlight'));
    }

    public function testParseWordAttribute(): void
    {
        $para = $this->parseInline('word{.class}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'class'));
    }

    public function testParseLinkWithAttributes(): void
    {
        $para = $this->parseInline('[Link](url){.external}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $class = $link->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'external'));
    }

    public function testParseSmartDoubleQuotes(): void
    {
        $para = $this->parseInline('"Hello"');

        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertStringContainsString("\u{201C}", $text->getContent()); // Left double quote
        $this->assertStringContainsString("\u{201D}", $text->getContent()); // Right double quote
    }

    public function testParseSmartSingleQuotes(): void
    {
        $para = $this->parseInline("'Hello'");

        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertStringContainsString("\u{2018}", $text->getContent()); // Left single quote
        $this->assertStringContainsString("\u{2019}", $text->getContent()); // Right single quote
    }

    public function testParseEmDash(): void
    {
        $para = $this->parseInline('word---word');

        // Multiple text nodes may be created, collect all content
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertStringContainsString("\u{2014}", $content); // Em dash
    }

    public function testParseEnDash(): void
    {
        $para = $this->parseInline('word--word');

        // Multiple text nodes may be created, collect all content
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertStringContainsString("\u{2013}", $content); // En dash
    }

    public function testParseEllipsis(): void
    {
        $para = $this->parseInline('wait...');

        $text = $this->getFirstChild($para);
        $this->assertInstanceOf(Text::class, $text);
        $this->assertStringContainsString("\u{2026}", $text->getContent()); // Ellipsis
    }

    public function testEmptyEmphasisIsLiteral(): void
    {
        $para = $this->parseInline('__');

        // May be split into multiple text nodes
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertSame('__', $content);
    }

    public function testCodeSpanInsideStrong(): void
    {
        $para = $this->parseInline('*foo `*`*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        // Should contain code span
        $hasCode = false;
        foreach ($strong->getChildren() as $child) {
            if ($child instanceof Code) {
                $hasCode = true;
                $this->assertSame('*', $child->getContent());
            }
        }
        $this->assertTrue($hasCode, 'Strong should contain code span');
    }

    public function testAttributesInsideEmphasis(): void
    {
        $para = $this->parseInline('*b{#id key="*"}*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        // Should contain span with attribute
        $hasSpan = false;
        foreach ($strong->getChildren() as $child) {
            if ($child instanceof Span) {
                $hasSpan = true;
                $this->assertSame('id', $child->getAttribute('id'));
                $this->assertSame('*', $child->getAttribute('key'));
            }
        }
        $this->assertTrue($hasSpan, 'Strong should contain span with attributes');
    }

    public function testNestedEmphasis(): void
    {
        $para = $this->parseInline('*_nested_*');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);

        $em = $strong->getChildren()[0] ?? null;
        $this->assertInstanceOf(Emphasis::class, $em);
    }

    public function testAutolinkPrecedenceOverEmphasis(): void
    {
        // Autolinks should be protected from emphasis delimiter matching
        // The _ in the URL should not be treated as an emphasis closer
        $para = $this->parseInline('_<http://example.com/a_b>');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        // First should be literal underscore
        $this->assertInstanceOf(Text::class, $children[0]);
        $this->assertSame('_', $children[0]->getContent());

        // Second should be the autolink
        $this->assertInstanceOf(Link::class, $children[1]);
        $this->assertSame('http://example.com/a_b', $children[1]->getDestination());
    }

    public function testEmphasisWithUnderscoreInLinkUrl(): void
    {
        // Issue #70: underscores in link URLs should not break emphasis
        $para = $this->parseInline('_[link](http://example.com?foo_bar=1), more text_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        // Should contain a link node followed by text
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('http://example.com?foo_bar=1', $emChildren[0]->getDestination());
    }

    public function testEmphasisWithUnderscoreInLinkUrlMoreCases(): void
    {
        // _hello [link](a_b) world_
        $para = $this->parseInline('_hello [link](a_b) world_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('hello ', $emChildren[0]->getContent());
        $this->assertInstanceOf(Link::class, $emChildren[1]);
        $this->assertSame('a_b', $emChildren[1]->getDestination());
    }

    public function testStrongWithStarInLinkUrl(): void
    {
        // *[closed](hello*) - star in URL should not break strong
        $para = $this->parseInline('*[link](http://example.com?q=a*b) text*');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Strong::class, $children[0]);
    }

    public function testEmphasisFollowedByCloseBrace(): void
    {
        // Emphasis opener cannot be followed by } (closer marker)
        $para = $this->parseInline('_}b_');

        // Should all be literal text
        $content = '';
        foreach ($para->getChildren() as $child) {
            if ($child instanceof Text) {
                $content .= $child->getContent();
            }
        }
        $this->assertSame('_}b_', $content);
    }

    public function testParseBooleanAttribute(): void
    {
        // {disabled} should create a boolean attribute with empty value
        $para = $this->parseInline('[Click me]{disabled}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
    }

    public function testParseBooleanAttributeWithClass(): void
    {
        // Boolean attr combined with class
        $para = $this->parseInline('[Submit]{disabled .btn}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
    }

    public function testParseBooleanAttributeWithIdAndClass(): void
    {
        // Boolean attr combined with class and id
        $para = $this->parseInline('[Submit]{.btn disabled #submit}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('disabled'));
        $this->assertSame('submit', $span->getAttribute('id'));
        $class = $span->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
    }

    public function testParseMultipleBooleanAttributes(): void
    {
        // Multiple boolean attrs
        $para = $this->parseInline('[Secret]{hidden readonly}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('', $span->getAttribute('hidden'));
        $this->assertSame('', $span->getAttribute('readonly'));
    }

    public function testParseBooleanAttributeOnLink(): void
    {
        // Boolean attr on a link
        $para = $this->parseInline('[Download](file.zip){download}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('', $link->getAttribute('download'));
    }

    public function testBooleanAttributeNotMatchedInsideQuotedValue(): void
    {
        // Words inside quoted values should NOT be treated as boolean attributes
        $para = $this->parseInline('[CSS]{abbr="Cascading Style Sheets"}');

        $span = $this->getFirstChild($para);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('Cascading Style Sheets', $span->getAttribute('abbr'));
        // These words should NOT exist as attributes
        $this->assertNull($span->getAttribute('Cascading'));
        $this->assertNull($span->getAttribute('Style'));
        $this->assertNull($span->getAttribute('Sheets'));
    }

    public function testBooleanAttributeWithQuotedValueAndClass(): void
    {
        // Boolean + quoted value + class should all work correctly
        $para = $this->parseInline('[Get it](file.zip){download title="Download file" .btn}');

        $link = $this->getFirstChild($para);
        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('', $link->getAttribute('download'));
        $this->assertSame('Download file', $link->getAttribute('title'));
        $class = $link->getAttribute('class') ?? '';
        $this->assertTrue(str_contains($class, 'btn'));
        // "Download" and "file" should NOT be boolean attributes
        $this->assertNull($link->getAttribute('Download'));
        $this->assertNull($link->getAttribute('file'));
    }
}
