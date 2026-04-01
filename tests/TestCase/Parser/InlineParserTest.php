<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\EscapedText;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
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

        $children = $para->getChildren();
        $this->assertCount(3, $children);

        // First child is escaped asterisk
        $this->assertInstanceOf(EscapedText::class, $children[0]);
        $this->assertSame('*', $children[0]->getContent());

        // Second child is text
        $this->assertInstanceOf(Text::class, $children[1]);
        $this->assertSame('not strong', $children[1]->getContent());

        // Third child is escaped asterisk
        $this->assertInstanceOf(EscapedText::class, $children[2]);
        $this->assertSame('*', $children[2]->getContent());
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

    /**
     * Test: Underscore in link URL should not break emphasis
     *
     * This is the main issue from https://github.com/jgm/djot/issues/375
     * _[link](http://example.com?foo_bar=1), more text_
     * Should produce emphasis around the entire content, with a working link.
     */
    public function testEmphasisWithUnderscoreInLinkDestination(): void
    {
        $para = $this->parseInline('_[link](http://example.com?foo_bar=1), more text_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        // Should contain a link node followed by text
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('http://example.com?foo_bar=1', $emChildren[0]->getDestination());

        // Verify link text
        $linkChildren = $emChildren[0]->getChildren();
        $this->assertInstanceOf(Text::class, $linkChildren[0]);
        $this->assertSame('link', $linkChildren[0]->getContent());
    }

    /**
     * Test: Simple underscore in path should not break emphasis
     */
    public function testEmphasisWithUnderscoreInSimplePath(): void
    {
        $para = $this->parseInline('_hello [link](a_b) world_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('hello ', $emChildren[0]->getContent());
        $this->assertInstanceOf(Link::class, $emChildren[1]);
        $this->assertSame('a_b', $emChildren[1]->getDestination());
        $this->assertInstanceOf(Text::class, $emChildren[2]);
        $this->assertSame(' world', $emChildren[2]->getContent());
    }

    /**
     * Test: Star in link URL should not break strong
     */
    public function testStrongWithStarInLinkDestination(): void
    {
        $para = $this->parseInline('*[link](http://example.com?q=a*b) text*');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Strong::class, $children[0]);

        $strongChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $strongChildren[0]);
        $this->assertSame('http://example.com?q=a*b', $strongChildren[0]->getDestination());
    }

    /**
     * Test: Multiple underscores in URL
     */
    public function testEmphasisWithMultipleUnderscoresInDestination(): void
    {
        $para = $this->parseInline('_[link](path/to_file_name_here)_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('path/to_file_name_here', $emChildren[0]->getDestination());
    }

    /**
     * Test: Nested parentheses in URL should be handled correctly
     */
    public function testEmphasisWithNestedParensInDestination(): void
    {
        $para = $this->parseInline('_[wiki](http://en.wikipedia.org/wiki/Foo_(bar))_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('http://en.wikipedia.org/wiki/Foo_(bar)', $emChildren[0]->getDestination());
    }

    /**
     * Test: Escaped underscore in URL
     */
    public function testEmphasisWithEscapedUnderscoreInDestination(): void
    {
        $para = $this->parseInline('_[link](path/to\_file)_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        // Escape is processed, so _ becomes literal
        $this->assertSame('path/to_file', $emChildren[0]->getDestination());
    }

    /**
     * Test: Image with underscore in URL should also work
     */
    public function testEmphasisWithUnderscoreInImageDestination(): void
    {
        $para = $this->parseInline('_![alt](image_file.png)_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Image::class, $emChildren[0]);
        $this->assertSame('image_file.png', $emChildren[0]->getSource());
    }

    /**
     * Test: Multiple links with underscores in URLs
     */
    public function testEmphasisWithMultipleLinksWithUnderscores(): void
    {
        $para = $this->parseInline('_[a](x_y) and [b](p_q)_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Link::class, $emChildren[0]);
        $this->assertSame('x_y', $emChildren[0]->getDestination());
        $this->assertInstanceOf(Text::class, $emChildren[1]);
        $this->assertSame(' and ', $emChildren[1]->getContent());
        $this->assertInstanceOf(Link::class, $emChildren[2]);
        $this->assertSame('p_q', $emChildren[2]->getDestination());
    }

    /**
     * Test: Underscore in link text still triggers emphasis (bracket-text case)
     *
     * This is intentionally NOT fixed by the destination-only approach.
     * _[foo_](url) should still produce emphasis [foo then ](url) as text.
     */
    public function testUnderscoreInLinkTextStillTriggersEmphasis(): void
    {
        $para = $this->parseInline('_[bar_](url)');

        $children = $para->getChildren();
        // The _ inside [bar_] closes the emphasis started at the beginning
        // Result: <em>[bar</em>](url)
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('[bar', $emChildren[0]->getContent());

        // Remaining text after emphasis
        $this->assertInstanceOf(Text::class, $children[1]);
        $this->assertSame('](url)', $children[1]->getContent());
    }

    /**
     * Test: Unclosed link destination doesn't break emphasis
     */
    public function testEmphasisWithUnclosedLinkDestination(): void
    {
        // [foo](_bar is not a complete link, so emphasis should still work
        $para = $this->parseInline('_text [foo](_bar more_');

        $children = $para->getChildren();
        // This is complex - the [foo]( triggers unclosed link handling
        // The emphasis should close on the last _
        $this->assertInstanceOf(Emphasis::class, $children[0]);
    }

    /**
     * Test: Link destination without preceding bracket should not affect emphasis
     */
    public function testEmphasisWithParensNotPrecededByBracket(): void
    {
        // Just (a_b) without [] before it - underscore should close emphasis
        $para = $this->parseInline('_foo (a_b) bar_');

        $children = $para->getChildren();
        // The _ in (a_b) closes emphasis because there's no ]( pattern
        $this->assertInstanceOf(Emphasis::class, $children[0]);
        $emChildren = $children[0]->getChildren();
        $this->assertInstanceOf(Text::class, $emChildren[0]);
        $this->assertSame('foo (a', $emChildren[0]->getContent());
    }

    /**
     * Test: Complex real-world URL with query params and underscores
     */
    public function testEmphasisWithComplexQueryString(): void
    {
        $para = $this->parseInline('_Check [this API](https://api.example.com/v1/users?sort_by=name&filter_type=active) for details_');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Emphasis::class, $children[0]);

        $emChildren = $children[0]->getChildren();
        $found = false;
        foreach ($emChildren as $child) {
            if ($child instanceof Link) {
                $this->assertSame('https://api.example.com/v1/users?sort_by=name&filter_type=active', $child->getDestination());
                $found = true;
            }
        }
        $this->assertTrue($found, 'Link should be found within emphasis');
    }

    /**
     * Test: Strong (star) with complex URL
     */
    public function testStrongWithComplexUrl(): void
    {
        $para = $this->parseInline('*Visit [the page](http://example.com/path*with*stars) now*');

        $children = $para->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(Strong::class, $children[0]);

        $strongChildren = $children[0]->getChildren();
        $foundLink = false;
        foreach ($strongChildren as $child) {
            if ($child instanceof Link) {
                $this->assertSame('http://example.com/path*with*stars', $child->getDestination());
                $foundLink = true;
            }
        }
        $this->assertTrue($foundLink, 'Link should be found within strong');
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

    // ===== Trailing Attributes for Inline Elements =====

    public function testEmphasisWithTrailingAttributes(): void
    {
        $para = $this->parseInline('_emphasized text_{.highlight}');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('highlight', $em->getAttribute('class'));
    }

    public function testStrongWithTrailingAttributes(): void
    {
        $para = $this->parseInline('*strong text*{.important #main}');

        $strong = $this->getFirstChild($para);
        $this->assertInstanceOf(Strong::class, $strong);
        $this->assertSame('important', $strong->getAttribute('class'));
        $this->assertSame('main', $strong->getAttribute('id'));
    }

    public function testCodeSpanWithTrailingAttributes(): void
    {
        $para = $this->parseInline('`code`{.lang-js}');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('lang-js', $code->getAttribute('class'));
    }

    public function testCodeSpanWithMultipleAttributes(): void
    {
        $para = $this->parseInline('`const x = 1`{.javascript data-line="5"}');

        $code = $this->getFirstChild($para);
        $this->assertInstanceOf(Code::class, $code);
        $this->assertSame('javascript', $code->getAttribute('class'));
        $this->assertSame('5', $code->getAttribute('data-line'));
    }

    public function testSuperscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('^2^{.exponent}');

        $sup = $this->getFirstChild($para);
        $this->assertInstanceOf(Superscript::class, $sup);
        $this->assertSame('exponent', $sup->getAttribute('class'));
    }

    public function testSubscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('~2~{.chemical}');

        $sub = $this->getFirstChild($para);
        $this->assertInstanceOf(Subscript::class, $sub);
        $this->assertSame('chemical', $sub->getAttribute('class'));
    }

    public function testBracedSuperscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{^text^}{.ref}');

        $sup = $this->getFirstChild($para);
        $this->assertInstanceOf(Superscript::class, $sup);
        $this->assertSame('ref', $sup->getAttribute('class'));
    }

    public function testBracedSubscriptWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{~text~}{.formula}');

        $sub = $this->getFirstChild($para);
        $this->assertInstanceOf(Subscript::class, $sub);
        $this->assertSame('formula', $sub->getAttribute('class'));
    }

    public function testHighlightWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{=highlighted=}{.match}');

        $mark = $this->getFirstChild($para);
        $this->assertInstanceOf(Highlight::class, $mark);
        $this->assertSame('match', $mark->getAttribute('class'));
    }

    public function testInsertWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{+inserted+}{.added}');

        $ins = $this->getFirstChild($para);
        $this->assertInstanceOf(Insert::class, $ins);
        $this->assertSame('added', $ins->getAttribute('class'));
    }

    public function testDeleteWithTrailingAttributes(): void
    {
        $para = $this->parseInline('{-deleted-}{.removed}');

        $del = $this->getFirstChild($para);
        $this->assertInstanceOf(Delete::class, $del);
        $this->assertSame('removed', $del->getAttribute('class'));
    }

    public function testSymbolWithTrailingAttributes(): void
    {
        $para = $this->parseInline(':emoji:{.large}');

        $symbol = $this->getFirstChild($para);
        $this->assertInstanceOf(Symbol::class, $symbol);
        $this->assertSame('emoji', $symbol->getName());
        $this->assertSame('large', $symbol->getAttribute('class'));
    }

    public function testTrailingAttributesDoNotAffectFollowingText(): void
    {
        $para = $this->parseInline('_text_{.cls} more text');

        $children = $para->getChildren();
        $this->assertCount(2, $children);

        $em = $children[0];
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('cls', $em->getAttribute('class'));

        $text = $children[1];
        $this->assertInstanceOf(Text::class, $text);
        $this->assertSame(' more text', $text->getContent());
    }

    public function testMultipleInlineElementsWithTrailingAttributes(): void
    {
        $para = $this->parseInline('_em_{.a} and *strong*{.b}');

        $children = $para->getChildren();
        $this->assertCount(3, $children);

        $this->assertInstanceOf(Emphasis::class, $children[0]);
        $this->assertSame('a', $children[0]->getAttribute('class'));

        $this->assertInstanceOf(Text::class, $children[1]);

        $this->assertInstanceOf(Strong::class, $children[2]);
        $this->assertSame('b', $children[2]->getAttribute('class'));
    }

    public function testNestedEmphasisWithTrailingAttributes(): void
    {
        $para = $this->parseInline('_outer *inner*_{.outer-class}');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertSame('outer-class', $em->getAttribute('class'));
    }

    public function testInlineElementWithoutTrailingAttributesStillWorks(): void
    {
        $para = $this->parseInline('_plain emphasis_ text');

        $em = $this->getFirstChild($para);
        $this->assertInstanceOf(Emphasis::class, $em);
        $this->assertEmpty($em->getAttributes());
    }
}
