<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\Div;
use PHPUnit\Framework\TestCase;

class SourceLineTrackingTest extends TestCase
{
    public function testDisabledByDefaultEmitsNoSourceLineAttribute(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("# Heading\n\nParagraph one.\n");

        $this->assertStringNotContainsString('data-source-line', $html);
    }

    public function testEnabledStampsTopLevelBlocksWithOneBasedSourceLine(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        // 1-based lines: 1 "# Heading", 3 "Paragraph one.", 5 "Paragraph two."
        $html = $converter->convert("# Heading\n\nParagraph one.\n\nParagraph two.\n");

        $this->assertStringContainsString('data-source-line="1"', $html);
        $this->assertStringContainsString('data-source-line="3"', $html);
        $this->assertStringContainsString('data-source-line="5"', $html);
    }

    public function testEnabledStampsListAndBlockquote(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        // 1-based: 1 "Intro", 3 "- item a", 4 "- item b", 6 "> quote"
        $html = $converter->convert("Intro\n\n- item a\n- item b\n\n> quote\n");

        // The list block and the blockquote each carry a source line.
        $this->assertMatchesRegularExpression('/<ul[^>]*data-source-line="3"/', $html);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*data-source-line="6"/', $html);
    }

    public function testEnabledStampsBlocksInsideBlockquote(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("> First paragraph.\n>\n> Second paragraph.\n");

        $this->assertMatchesRegularExpression('/<blockquote[^>]*data-source-line="1"/', $html);
        $this->assertStringContainsString('<p data-source-line="1">First paragraph.</p>', $html);
        $this->assertStringContainsString('<p data-source-line="3">Second paragraph.</p>', $html);
    }

    public function testBlockquoteLazyContinuationKeepsOriginalLine(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("> quoted\nlazy continuation\n\nAfter\n");

        $this->assertStringContainsString("<p data-source-line=\"1\">quoted\nlazy continuation</p>", $html);
        $this->assertStringContainsString('<p data-source-line="4">After</p>', $html);
    }

    public function testEnabledStampsBlocksInsideDiv(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("::: note\nNested paragraph.\n\n```\ncode\n```\n:::\n");

        $this->assertMatchesRegularExpression('/<div class="note" data-source-line="1"/', $html);
        $this->assertStringContainsString('<p data-source-line="2">Nested paragraph.</p>', $html);
        $this->assertStringContainsString('<pre data-source-line="4"><code>code', $html);
    }

    public function testEnabledStampsListItemsLooseParagraphsAndNestedSublists(): void
    {
        $converter = new DjotConverter(sourceLines: true, nestedListsWithoutBlankLine: true);
        $html = $converter->convert("- first\n\n  second\n\n  - nested\n    - deep\n");

        $this->assertMatchesRegularExpression('/<li data-source-line="1">/', $html);
        $this->assertStringContainsString('<p data-source-line="1">first</p>', $html);
        $this->assertStringContainsString('<p data-source-line="3">second</p>', $html);
        $this->assertMatchesRegularExpression('/<ul data-source-line="5">/', $html);
        $this->assertMatchesRegularExpression('/<li data-source-line="5">/', $html);
        $this->assertMatchesRegularExpression('/<ul data-source-line="6">/', $html);
        $this->assertMatchesRegularExpression('/<li data-source-line="6">/', $html);
    }

    public function testListSyntheticSeparatorDoesNotBecomeSourceLine(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("- first\n  {.note}\n  second\n");

        $this->assertStringContainsString('<p data-source-line="1">first</p>', $html);
        $this->assertStringContainsString('<p class="note" data-source-line="3">second</p>', $html);
        $this->assertStringNotContainsString('data-source-line="0"', $html);
    }

    public function testListInsideBlockquoteComposesLineMap(): void
    {
        $converter = new DjotConverter(sourceLines: true, nestedListsWithoutBlankLine: true);
        $html = $converter->convert("> - quoted item\n>   - nested item\n");

        $this->assertMatchesRegularExpression('/<ul data-source-line="1">/', $html);
        $this->assertMatchesRegularExpression('/<li data-source-line="1">/', $html);
        $this->assertStringContainsString('<p data-source-line="1">quoted item</p>', $html);
        $this->assertMatchesRegularExpression('/<ul data-source-line="2">/', $html);
        $this->assertMatchesRegularExpression('/<li data-source-line="2">/', $html);
        $this->assertStringContainsString('<p data-source-line="2">nested item</p>', $html);
    }

    public function testFootnoteContentBlocksAreStamped(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("Text[^a]\n\n[^a]: foot one\n\n  foot two\n");

        $this->assertStringContainsString('<p data-source-line="3">foot one</p>', $html);
        $this->assertStringContainsString('<p data-source-line="5">foot two', $html);
    }

    public function testDefinitionListTermsAndDescriptionsAreStamped(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert(": term\n  definition\n");

        $this->assertMatchesRegularExpression('/<dl data-source-line="1">/', $html);
        $this->assertStringContainsString('<dt data-source-line="1">term</dt>', $html);
        $this->assertMatchesRegularExpression('/<dd data-source-line="2">/', $html);
        $this->assertStringContainsString('<p data-source-line="2">definition</p>', $html);
    }

    public function testNestedAuthorSourceLineAttributeIsNotOverwritten(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("> {data-source-line=99}\n> Nested\n");

        $this->assertStringContainsString('<p data-source-line="99">Nested</p>', $html);
    }

    public function testDisabledOutputHasNoNestedSourceLineAttributes(): void
    {
        $converter = new DjotConverter();
        $html = $converter->convert("> - item\n>   - nested\n");

        $this->assertStringNotContainsString('data-source-line', $html);
        $this->assertSame("<blockquote>\n<ul>\n<li>\nitem\n- nested\n</li>\n</ul>\n</blockquote>\n", $html);
    }

    public function testCrlfInputKeepsCorrectSourceLines(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("> first\r\n>\r\n> second\r\n");

        $this->assertStringContainsString('<p data-source-line="1">first</p>', $html);
        $this->assertStringContainsString('<p data-source-line="3">second</p>', $html);
    }

    public function testSourceLineRendersAfterAuthorAttributes(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $html = $converter->convert("{.note}\nParagraph with class.\n");

        // The attribute is appended after author attributes (attribute order).
        $this->assertStringContainsString('<p class="note" data-source-line="2">', $html);
    }

    public function testCustomBlockContentIsNotStampedWithLocalLines(): void
    {
        $converter = new DjotConverter(sourceLines: true);
        $parser = $converter->getParser();
        $parser->addBlockPattern('/^!!!\s*$/', function ($lines, $start, $parent, $p) {
            $content = [];
            $i = $start + 1;
            $count = count($lines);
            while ($i < $count && preg_match('/^\s+(.*)$/', $lines[$i], $match)) {
                $content[] = $match[1];
                $i++;
            }

            $div = new Div();
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start;
        });
        $html = $converter->convert("Intro\n\n!!!\n  nested\n");

        // The custom block node itself is stamped with its document line, but
        // its extracted content has no document positions - the nested
        // paragraph must not be mis-stamped with a local index (line 1).
        $this->assertStringContainsString('<p data-source-line="1">Intro</p>', $html);
        $this->assertStringContainsString('<div data-source-line="3">', $html);
        $this->assertStringContainsString('<p>nested</p>', $html);
    }
}
