<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\Span;
use Djot\Parser\Block\TableParser;
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class LinearFootnotesAndAttributesTest extends TestCase
{
    public function testMarkdownSiblingBoundariesMatchUncachedTraversalAcrossRenders(): void
    {
        $cached = new MarkdownRenderer();
        $uncached = new class extends MarkdownRenderer {
        };
        $converter = DjotConverter::create();
        foreach (['*word* **word**', 'a[*!*]{.x}b', '[*one* **two**]{.x}', '*one* *two* *three*'] as $source) {
            $document = $converter->parse($source);
            $this->assertSame($uncached->render($document), $cached->render($document));
            $this->assertSame($uncached->render($document), $cached->render($document));
        }
    }

    public function testAlternatingTablesAndLineBlocksKeepTheirBoundaries(): void
    {
        $html = DjotConverter::create()->convert(str_repeat("|a|\n| b\n", 512));
        $this->assertSame(512, substr_count($html, '<table>'));
    }

    public function testTableClassificationWorkStopsAtSeparatorContinuations(): void
    {
        $tables = new class extends TableParser {
            public int $calls = 0;

            public function stripRowAttributes(string $line): string
            {
                $this->calls++;

                return parent::stripRowAttributes($line);
            }
        };
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        (new ReflectionProperty(BlockParser::class, 'tableParser'))->setValue($parser, $tables);
        $converter = new DjotConverter(parser: $parser);
        $html = $converter->convert("plain ^ text\n\n" . str_repeat("|a|\n|-|\n+b|\n", 512));
        $this->assertSame(512, substr_count($html, '<table>'));
        $this->assertLessThan(512 * 32, $tables->calls);
    }

    public function testCustomBlockContentMayIntroduceRowspans(): void
    {
        $parser = new BlockParser();
        $document = $parser->parse('plain');
        $parser->parseBlockContent($document, ['| a | b |', '| ^ | ^ |']);
        $this->assertStringContainsString('<td rowspan="2">a</td>', (new HtmlRenderer())->render($document));
    }

    public function testChainedAndCyclicFootnotesKeepDiscoveryOrder(): void
    {
        $converter = DjotConverter::create();
        $html = $converter->convert("[^a] [^c]\n\n[^a]: a [^b]\n\n[^b]: b [^a]\n\n[^c]: c [^d]\n\n[^d]: d\n");
        $this->assertSame(4, substr_count($html, '<li id="fn'));
        $this->assertStringContainsString('<li id="fn3"', $html);
        $this->assertStringContainsString('<li id="fn4"', $html);
        $this->assertStringContainsString('fnref1-2', $html);
        $this->assertSame("<p>plain</p>\n", $converter->convert('plain'));
    }

    public function testLongChainRendersEveryNoteOnce(): void
    {
        $source = "[^n0]\n\n";
        for ($i = 0; $i < 256; $i++) {
            $source .= '[^n' . $i . ']: note' . ($i < 255 ? ' [^n' . ($i + 1) . ']' : '') . "\n\n";
        }
        $html = DjotConverter::create()->convert($source);
        $this->assertSame(256, substr_count($html, '<li id="fn'));
        $this->assertStringContainsString('<li id="fn256"', $html);
    }

    public function testClassReplacementAndRemovalInvalidateMembership(): void
    {
        $node = new Span();
        $node->setAttribute('class', 'a a  b');
        $node->addClass('a');
        $this->assertSame('a a  b', $node->getAttribute('class'));
        $node->addClass('c');
        $this->assertSame('a a b c', $node->getAttribute('class'));
        $node->setAttributes(['class' => 'x']);
        $node->addClass('a');
        $this->assertSame('x a', $node->getAttribute('class'));
        $node->removeAttribute('class');
        $node->addClass('a');
        $this->assertSame('a', $node->getAttribute('class'));
    }

    public function testCompositeClassesKeepTheExistingAppendBehavior(): void
    {
        $node = new Span();
        $node->addClass('a b');
        $node->addClass('a b');
        $this->assertSame('a b a b', $node->getAttribute('class'));
    }

    public function testCustomAttributeAccessStillReceivesClassWrites(): void
    {
        $node = new class extends Span {
            public int $writes = 0;

            public function setAttribute(string $key, string $value): void
            {
                $this->writes++;
                parent::setAttribute($key, $value);
            }
        };
        $node->addClass('a');
        $node->addClass('a');
        $node->addClass('b');
        $this->assertSame(2, $node->writes);
        $this->assertSame('a b', $node->getAttribute('class'));
    }

    public function testLargeAbbreviationDictionaryStillExpandsMatchingWords(): void
    {
        $source = '';
        for ($i = 0; $i < 8192; $i++) {
            $source .= '*[A' . $i . "]: expansion\n";
        }
        $source .= "\nA8191 A0 AX A0B\n";
        $html = DjotConverter::create()->convert($source);
        $this->assertStringContainsString('<abbr title="expansion">A8191</abbr>', $html);
        $this->assertStringContainsString('<abbr title="expansion">A0</abbr>', $html);
        $this->assertSame(2, substr_count($html, '<abbr'));
        $this->assertStringContainsString('A0B', $html);
    }

    public function testTallFullRowSpansAndHeaderReplacementKeepTheirOrigins(): void
    {
        $html = DjotConverter::create()->convert("| a | b |\n|---|---|\n" . str_repeat("| ^ | ^ |\n", 1000));
        $this->assertSame(2, substr_count($html, 'rowspan="1001"'));
        $this->assertStringContainsString('<th rowspan="1001">a</th>', $html);
    }

    public function testMixedSpansKeepTheExistingLayout(): void
    {
        $html = DjotConverter::create()->convert("| y | ^ | ^ |\n| < | ^ |  |  |\n| < | y | ^ |  |\n| y |  |\n");
        $this->assertSame("<table>\n<tr>\n<td>y</td>\n<td rowspan=\"2\"></td>\n<td></td>\n</tr>\n<tr>\n<td></td>\n<td rowspan=\"2\"></td>\n<td></td>\n</tr>\n<tr>\n<td></td>\n<td></td>\n</tr>\n<tr>\n<td>y</td>\n<td></td>\n</tr>\n</table>\n", $html);
    }

    public function testFootnoteHooksMayMoveThePublicNumberingArrayCursor(): void
    {
        $renderer = new class extends HtmlRenderer {
            protected function renderFootnoteRef(FootnoteRef $node): string
            {
                reset($this->getRenderContext()->footnoteNumbers);

                return parent::renderFootnoteRef($node);
            }
        };
        $html = DjotConverter::create(renderer: $renderer)->convert("[^a]\n\n[^a]: a [^b]\n\n[^b]: b [^c]\n\n[^c]: c\n");
        $this->assertSame(3, substr_count($html, '<li id="fn'));
    }

    public function testWhitespaceOnlyClassValuesNormalizeAtTheSameAppend(): void
    {
        $node = new Span();
        $node->setAttribute('class', ' ');
        $node->addClass('c');
        $this->assertSame(' c', $node->getAttribute('class'));
        $node->addClass('d');
        $this->assertSame('c d', $node->getAttribute('class'));
    }

    public function testMixedTableMarkersDoNotDuplicateInlineWarnings(): void
    {
        $converter = new DjotConverter(warnings: true);
        $converter->convert("| [x][missing] | a |\n| ^ | b |\n");
        $warnings = array_filter($converter->getWarnings(), static fn ($warning): bool => str_contains($warning->getMessage(), "Undefined reference 'missing'"));
        $this->assertCount(1, $warnings);
    }

    public function testCustomNodesMayReplaceProtectedClassStorage(): void
    {
        $node = new class extends Span {
            public function replaceClass(string $value): void
            {
                $this->attributes['class'] = $value;
            }
        };
        $node->addClass(str_repeat('a', 128));
        $node->addClass('b');
        $node->replaceClass('x');
        $node->addClass('b');
        $this->assertSame('x b', $node->getAttribute('class'));
    }
}
