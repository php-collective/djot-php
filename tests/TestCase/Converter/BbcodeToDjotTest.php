<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\BbcodeToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

class BbcodeToDjotTest extends TestCase
{
    protected BbcodeToDjot $converter;

    protected function setUp(): void
    {
        $this->converter = new BbcodeToDjot();
    }

    // ==================== Basic Formatting ====================

    public function testBold(): void
    {
        $this->assertSame("*bold text*\n", $this->converter->convert('[b]bold text[/b]'));
    }

    public function testItalic(): void
    {
        $this->assertSame("_italic text_\n", $this->converter->convert('[i]italic text[/i]'));
    }

    public function testUnderline(): void
    {
        $this->assertSame("{+underlined+}\n", $this->converter->convert('[u]underlined[/u]'));
    }

    public function testStrikethrough(): void
    {
        $this->assertSame("{-deleted-}\n", $this->converter->convert('[s]deleted[/s]'));
    }

    public function testSuperscript(): void
    {
        $this->assertSame("E=mc^2^\n", $this->converter->convert('E=mc[sup]2[/sup]'));
    }

    public function testSubscript(): void
    {
        $this->assertSame("H~2~O\n", $this->converter->convert('H[sub]2[/sub]O'));
    }

    public function testNestedFormatting(): void
    {
        $this->assertSame("*_bold italic_*\n", $this->converter->convert('[b][i]bold italic[/i][/b]'));
    }

    public function testSizeStripped(): void
    {
        $this->assertSame("large text\n", $this->converter->convert('[size=20]large text[/size]'));
    }

    public function testColorStripped(): void
    {
        $this->assertSame("red text\n", $this->converter->convert('[color=red]red text[/color]'));
    }

    // ==================== Links ====================

    public function testLinkWithText(): void
    {
        $this->assertSame(
            "[Example](https://example.com)\n",
            $this->converter->convert('[url=https://example.com]Example[/url]'),
        );
    }

    public function testLinkWithoutText(): void
    {
        $this->assertSame(
            "<https://example.com>\n",
            $this->converter->convert('[url]https://example.com[/url]'),
        );
    }

    public function testEmail(): void
    {
        $this->assertSame(
            "<mailto:test@example.com>\n",
            $this->converter->convert('[email]test@example.com[/email]'),
        );
    }

    // ==================== Images ====================

    public function testImage(): void
    {
        $this->assertSame(
            "![](https://example.com/image.jpg)\n",
            $this->converter->convert('[img]https://example.com/image.jpg[/img]'),
        );
    }

    public function testImageWithSize(): void
    {
        $this->assertSame(
            "![](https://example.com/image.jpg)\n",
            $this->converter->convert('[img=100x100]https://example.com/image.jpg[/img]'),
        );
    }

    // ==================== Code ====================

    public function testCodeBlock(): void
    {
        $result = $this->converter->convert("[code]echo 'hello';[/code]");
        $this->assertStringContainsString("```\n", $result);
        $this->assertStringContainsString("echo 'hello';", $result);
    }

    public function testCodeBlockWithLanguage(): void
    {
        $result = $this->converter->convert("[code=php]echo 'hello';[/code]");
        $this->assertStringContainsString("```php\n", $result);
        $this->assertStringContainsString("echo 'hello';", $result);
    }

    public function testInlineCode(): void
    {
        $this->assertSame(
            "Use `print()` function\n",
            $this->converter->convert('Use [c]print()[/c] function'),
        );
    }

    // ==================== Quotes ====================

    public function testQuote(): void
    {
        $result = $this->converter->convert('[quote]This is quoted[/quote]');
        $this->assertStringContainsString('> This is quoted', $result);
    }

    public function testQuoteWithAuthor(): void
    {
        $result = $this->converter->convert('[quote=John]This is quoted[/quote]');
        $this->assertStringContainsString('> This is quoted', $result);
        $this->assertStringContainsString('^ John', $result);
    }

    public function testNestedQuotes(): void
    {
        $bbcode = '[quote=Outer]First level[quote=Inner]Second level[/quote][/quote]';
        $result = $this->converter->convert($bbcode);

        // Both quote authors should be present
        $this->assertStringContainsString('^ Outer', $result);
        $this->assertStringContainsString('^ Inner', $result);
        $this->assertStringContainsString('First level', $result);
        $this->assertStringContainsString('Second level', $result);
        // No unprocessed BBCode tags should remain
        $this->assertStringNotContainsString('[quote', $result);
        $this->assertStringNotContainsString('[/quote]', $result);
    }

    public function testDeeplyNestedQuotes(): void
    {
        $bbcode = '[quote=A]Level 1[quote=B]Level 2[quote=C]Level 3[/quote][/quote][/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ A', $result);
        $this->assertStringContainsString('^ B', $result);
        $this->assertStringContainsString('^ C', $result);
        $this->assertStringContainsString('Level 1', $result);
        $this->assertStringContainsString('Level 2', $result);
        $this->assertStringContainsString('Level 3', $result);
        $this->assertStringNotContainsString('[quote', $result);
        $this->assertStringNotContainsString('[/quote]', $result);
    }

    public function testNestedQuotesWithoutAuthor(): void
    {
        $bbcode = '[quote]Outer[quote]Inner[/quote][/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('> Outer', $result);
        $this->assertStringContainsString('> Inner', $result);
        $this->assertStringNotContainsString('[quote', $result);
        $this->assertStringNotContainsString('[/quote]', $result);
    }

    public function testNestedQuotesWithTextBetween(): void
    {
        $bbcode = 'Before [quote=User]Quote content[/quote] After';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
        $this->assertStringContainsString('^ User', $result);
        $this->assertStringContainsString('Quote content', $result);
    }

    public function testMultipleQuotesAtSameLevel(): void
    {
        $bbcode = '[quote=A]First[/quote] text [quote=B]Second[/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ A', $result);
        $this->assertStringContainsString('^ B', $result);
        $this->assertStringContainsString('First', $result);
        $this->assertStringContainsString('Second', $result);
        $this->assertStringContainsString('text', $result);
    }

    public function testQuoteWithComplexAttributes(): void
    {
        // Forum-style quote with date attribute
        $bbcode = '[quote=username date="2024-01-01"]Content[/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ username (2024-01-01)', $result);
        $this->assertStringContainsString('Content', $result);

        // With date and time as separate attributes
        $bbcode = '[quote=username date="2024-01-01" time="12:30"]Content[/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ username (2024-01-01 12:30)', $result);

        // Full forum format with ID, name, and datetime
        $bbcode = '[quote="9" name="superadmin" date="2025-12-06 18:35:26"]Content[/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ superadmin (2025-12-06 18:35:26) #9', $result);

        // With id= attribute instead of bare value
        $bbcode = '[quote id="42" name="admin" date="2024-01-01"]Content[/quote]';
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('^ admin (2024-01-01) #42', $result);
    }

    // ==================== Lists ====================

    public function testUnorderedList(): void
    {
        $bbcode = "[list]\n[*]Item 1\n[*]Item 2\n[/list]";
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('- Item 1', $result);
        $this->assertStringContainsString('- Item 2', $result);
    }

    public function testOrderedList(): void
    {
        $bbcode = "[list=1]\n[*]First\n[*]Second\n[/list]";
        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('1. First', $result);
        $this->assertStringContainsString('2. Second', $result);
    }

    // ==================== Tables ====================

    public function testTable(): void
    {
        $bbcode = <<<'BBCODE'
[table]
[tr][th]Name[/th][th]Age[/th][/tr]
[tr][td]Alice[/td][td]30[/td][/tr]
[/table]
BBCODE;

        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('| Name | Age |', $result);
        $this->assertStringContainsString('| --- | --- |', $result);
        $this->assertStringContainsString('| Alice | 30 |', $result);
    }

    // ==================== Other Elements ====================

    public function testHorizontalRule(): void
    {
        $this->assertStringContainsString('---', $this->converter->convert('text[hr]more'));
    }

    public function testSpoiler(): void
    {
        $result = $this->converter->convert('[spoiler]Hidden content[/spoiler]');
        $this->assertStringContainsString('::: spoiler', $result);
        $this->assertStringContainsString('Hidden content', $result);
        $this->assertStringContainsString(':::', $result);
    }

    public function testSpoilerWithTitle(): void
    {
        $result = $this->converter->convert('[spoiler=Click to reveal]Hidden[/spoiler]');
        $this->assertStringContainsString('{title="Click to reveal"}', $result);
        $this->assertStringContainsString('::: spoiler', $result);
        $this->assertStringContainsString('Hidden', $result);
    }

    public function testYoutube(): void
    {
        $this->assertSame(
            "![YouTube Video](https://www.youtube.com/watch?v=dQw4w9WgXcQ)\n",
            $this->converter->convert('[youtube]dQw4w9WgXcQ[/youtube]'),
        );
    }

    // ==================== Complex Examples ====================

    public function testComplexPost(): void
    {
        $bbcode = <<<'BBCODE'
[b]Welcome to the Forum![/b]

Here's some [i]important[/i] information:

[list]
[*]Read the rules
[*]Be respectful
[/list]

[quote=Admin]Please follow the guidelines[/quote]

Check out [url=https://example.com]our website[/url].
BBCODE;

        $result = $this->converter->convert($bbcode);

        $this->assertStringContainsString('*Welcome to the Forum!*', $result);
        $this->assertStringContainsString('_important_', $result);
        $this->assertStringContainsString('- Read the rules', $result);
        $this->assertStringContainsString('^ Admin', $result);
        $this->assertStringContainsString('[our website](https://example.com)', $result);
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame("*BOLD*\n", $this->converter->convert('[B]BOLD[/B]'));
    }

    public function testUnknownTagsPreserved(): void
    {
        // Unknown tags without closing are preserved (safer than stripping everything)
        // Closing tags are stripped
        $this->assertSame("[unknown]content\n", $this->converter->convert('[unknown]content[/unknown]'));
    }

    // ==================== Block Separation ====================

    public function testListHasBlankLineBefore(): void
    {
        // Lists should have blank line before them for proper Djot block separation
        $result = $this->converter->convert('Some text[list][*]item 1[*]item 2[/list]');
        $this->assertStringContainsString("Some text\n\n- item 1", $result);
    }

    public function testOrderedListHasBlankLineBefore(): void
    {
        $result = $this->converter->convert('Intro[list=1][*]First[*]Second[/list]');
        $this->assertStringContainsString("Intro\n\n1. First", $result);
    }

    public function testCodeBlockHasBlankLinesBefore(): void
    {
        $result = $this->converter->convert('Some text[code]echo hello;[/code]More text');
        $this->assertStringContainsString("Some text\n\n```\n", $result);
        $this->assertStringContainsString("```\n\nMore text", $result);
    }

    public function testQuoteHasBlankLineBefore(): void
    {
        $result = $this->converter->convert('Some text[quote]A quote[/quote]More text');
        $this->assertStringContainsString("Some text\n\n> A quote", $result);
    }

    public function testTableHasBlankLineBefore(): void
    {
        $result = $this->converter->convert('Some text[table][tr][td]Cell[/td][/tr][/table]More text');
        $this->assertStringContainsString("Some text\n\n| Cell |", $result);
    }

    public function testCodeLangCannotMintRawHtmlBlock(): void
    {
        // An untrusted [code=..] language of `=html` must NOT become a
        // raw-HTML block; it stays an inert, escaped code block.
        foreach (['[code= =html]<script>alert(1)</script>[/code]', '[code==html]<script>x</script>[/code]'] as $bb) {
            $out = $this->converter->convert($bb);
            $html = (new DjotConverter())->convert($out);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public function testDeeplyNestedQuotesAreLinearAndCorrect(): void
    {
        // Regression: deeply nested [quote] was O(n^2) (a converter DoS). This
        // completes near-instantly and nesting is preserved.
        $n = 4000;
        $bb = str_repeat('[quote]', $n) . 'x' . str_repeat('[/quote]', $n);
        $out = $this->converter->convert($bb);
        $this->assertStringContainsString('x', $out);
        // A small nested case keeps its structure.
        $small = $this->converter->convert('[quote]a[quote]b[/quote]c[/quote]');
        $this->assertStringContainsString('> > b', $small);
    }
}
