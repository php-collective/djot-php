<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Span;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\BlockParser;
use Djot\Parser\InlineParser;
use Djot\Parser\Utility\BacktickRunIndex;
use Djot\Renderer\HtmlRenderer;
use LogicException;
use PHPUnit\Framework\TestCase;

final class InlineScanComplexityTest extends TestCase
{
    public function testUnclosedBracedOpenersDoNotStartBareDelimiterScans(): void
    {
        foreach (['_', '*', '^', '~'] as $delimiter) {
            $parser = new class (new BlockParser()) extends InlineParser {
                public int $delimiterProbes = 0;

                /**
                 * @return array{node: \Djot\Node\Node, pos: int}|null
                 */
                protected function parseDelimited(string $text, int $pos, string $delimiter, string $nodeClass): ?array
                {
                    $this->delimiterProbes++;

                    return parent::parseDelimited($text, $pos, $delimiter, $nodeClass);
                }
            };
            $input = str_repeat('{' . $delimiter . 'a', 512);
            $paragraph = new Paragraph();
            $parser->parse($paragraph, $input);
            self::assertSame('<p>' . $input . "</p>\n", (new HtmlRenderer())->renderNodeFragment($paragraph));
            self::assertSame(0, $parser->delimiterProbes);
        }
    }

    public function testFailedOpenersAreCachedWhenLaterOpenersInTheRunMatch(): void
    {
        foreach (['*' => 'strong', '_' => 'em'] as $delimiter => $tag) {
            $parser = new class (new BlockParser()) extends InlineParser {
                public int $codeProbes = 0;

                protected function findCodeSpanEnd(string $text, int $pos): ?int
                {
                    $this->codeProbes++;

                    return parent::findCodeSpanEnd($text, $pos);
                }
            };
            $input = str_repeat($delimiter . $delimiter . 'a' . $delimiter . ' `code` ', 512);
            $expected = str_repeat($delimiter . '<' . $tag . '>a</' . $tag . '> <code>code</code> ', 512);
            $paragraph = new Paragraph();
            $parser->parse($paragraph, rtrim($input));
            self::assertSame('<p>' . rtrim($expected) . "</p>\n", (new HtmlRenderer())->renderNodeFragment($paragraph));
            self::assertLessThanOrEqual(512, $parser->codeProbes);
        }
    }

    public function testUnmatchedOpenerCachePreservesNestedMatchesAndParserReuse(): void
    {
        $converter = DjotConverter::create();
        self::assertSame("<p>*<strong>a</strong> *<strong>b</strong></p>\n", $converter->convert('**a* **b*'));
        self::assertSame("<p>_<em>a</em> _<em>b</em></p>\n", $converter->convert('__a_ __b_'));
        self::assertSame("<p>**<strong>a</strong> *<strong>b</strong></p>\n", $converter->convert('***a* **b*'));
        self::assertSame("<p><strong><strong>a</strong></strong></p>\n", $converter->convert('**a**'));
        self::assertSame("<p><em><em>b</em></em></p>\n", $converter->convert('__b__'));
    }

    public function testClosingRunsPreserveSurplusDelimitersAndBracedClosers(): void
    {
        $converter = DjotConverter::create();
        self::assertSame("<p><strong><strong>a</strong></strong>*</p>\n", $converter->convert('**a***'));
        self::assertSame("<p>**<strong>a</strong>*}</p>\n", $converter->convert('***a**}'));
        self::assertSame("<p><strong><strong>a</strong>b</strong>*</p>\n", $converter->convert('**a*b**'));
        self::assertSame("<p><strong><strong>a<code>*</code></strong></strong></p>\n", $converter->convert('**a`*`**'));
    }

    public function testFailedEmphasisScansVisitOpaqueSpansOnce(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public int $codeProbes = 0;

            protected function findCodeSpanEnd(string $text, int $pos): ?int
            {
                $this->codeProbes++;

                return parent::findCodeSpanEnd($text, $pos);
            }
        };
        $parser->parse(new Paragraph(), str_repeat('_word `code` ', 512));
        self::assertLessThanOrEqual(512, $parser->codeProbes);
    }

    public function testOneAttributeScanIndexesNestedMatchesAndFailures(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public int $scans = 0;

            public function attributeEnd(string $text, int $pos): ?int
            {
                return $this->findAttributeEnd($text, $pos);
            }

            protected function scanAttributeEnd(string $text, int $pos): ?int
            {
                $this->scans++;

                return parent::scanAttributeEnd($text, $pos);
            }
        };
        $text = str_repeat('{x ', 512) . '}';
        for ($i = 0; $i < 512; $i++) {
            self::assertSame($i === 511 ? 1536 : null, $parser->attributeEnd($text, $i * 3));
        }
        self::assertSame(1, $parser->scans);
        self::assertSame(4, $parser->attributeEnd('{new}', 0));
        self::assertSame(2, $parser->scans);
    }

    public function testAttributeIndexDoesNotReuseOuterQuoteOrEscapeState(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public function attributeEnd(string $text, int $pos): ?int
            {
                return $this->findAttributeEnd($text, $pos);
            }
        };
        $quoted = '{a "{quoted}" {nested}}';
        self::assertSame(strlen($quoted) - 1, $parser->attributeEnd($quoted, 0));
        self::assertSame(strpos($quoted, '}'), $parser->attributeEnd($quoted, strpos($quoted, '{quoted}')));
        self::assertSame(strlen($quoted) - 2, $parser->attributeEnd($quoted, strpos($quoted, '{nested}')));

        $escaped = '{a {nested\}tail}}';
        self::assertSame(strlen($escaped) - 1, $parser->attributeEnd($escaped, 0));
        self::assertSame(strlen($escaped) - 2, $parser->attributeEnd($escaped, 3));
    }

    public function testFailedScansDoNotLeakAcrossDocumentsOrNestedSpans(): void
    {
        $converter = DjotConverter::create();
        self::assertSame("<p>_word _word</p>\n", $converter->convert('_word _word'));
        self::assertSame("<p>{+word {+word</p>\n", $converter->convert('{+word {+word'));
        self::assertSame("<p>&lt;word &lt;word&gt;</p>\n", $converter->convert('<word <word>'));
        self::assertSame("<p><em><strong>bold</strong></em></p>\n", $converter->convert('_*bold*_'));
        self::assertSame("<p><ins>yes</ins></p>\n", $converter->convert('{+yes+}'));
        self::assertSame("<p><a href=\"https://example.com\">https://example.com</a></p>\n", $converter->convert('<https://example.com>'));
        self::assertSame("<p><a href=\"mailto:x@example.com\">x@example.com</a></p>\n", $converter->convert('<x@example.com>'));
    }

    public function testDivCloserProbePreservesZeroLengthAndWhitespaceSemantics(): void
    {
        $parser = new FencedBlockParser();
        self::assertTrue($parser->isDivFenceCloser('', 0));
        self::assertTrue($parser->isDivFenceCloser('::: ', 3));
        self::assertFalse($parser->isDivFenceCloser(' :::', 3));
        self::assertFalse($parser->isDivFenceCloser('plain', 3));
    }

    public function testBacktickIndexMatchesMinimumWidthLookaheadAtEveryOffset(): void
    {
        foreach (['', 'plain', '`a```b``c`````d`', str_repeat('``a`b```c ', 12)] as $text) {
            $index = new BacktickRunIndex($text);
            preg_match_all('/`+/', $text, $runs, PREG_OFFSET_CAPTURE);
            for ($offset = strlen($text) + 1; $offset >= 0; $offset--) {
                for ($width = 1; $width <= 8; $width++) {
                    $expected = null;
                    foreach ($runs[0] as [$run, $start]) {
                        if ($start >= $offset && strlen($run) >= $width) {
                            $expected = $start + strlen($run);

                            break;
                        }
                    }
                    self::assertSame($expected, $index->findCloser($offset, $width));
                }
            }
        }
    }

    public function testBacktickLookaheadPreservesLongerRunBehaviorAndParserReuse(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public function codeEnd(string $text, int $pos): ?int
            {
                return $this->findCodeSpanEnd($text, $pos);
            }
        };
        self::assertSame(6, $parser->codeEnd('``a```b', 0));
        self::assertSame(6, $parser->codeEnd('`a````', 0));
        self::assertNull($parser->codeEnd('```a``', 0));
        self::assertSame(5, $parser->codeEnd('``b``', 0));
    }

    public function testDestinationIndexPreservesEscapesNestedPairsAndFailures(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public function destinationEnd(string $text, int $pos): ?int
            {
                return $this->findLinkDestinationEnd($text, $pos);
            }
        };
        $text = str_repeat('(x', 512) . ')';
        for ($i = 0; $i < 512; $i++) {
            self::assertSame($i === 511 ? 1025 : null, $parser->destinationEnd($text, $i * 2));
        }
        $escaped = '(a\\)b(c))';
        self::assertSame(strlen($escaped), $parser->destinationEnd($escaped, 0));
        self::assertSame(strlen($escaped) - 1, $parser->destinationEnd($escaped, 5));
    }

    public function testMalformedAutolinksRetainTheLastValidLinkAndQuotedEmails(): void
    {
        $converter = DjotConverter::create();
        self::assertSame(
            '<p>' . str_repeat('&lt;a:x ', 511) . '<a href="a:x">a:x</a></p>' . "\n",
            $converter->convert(str_repeat('<a:x ', 511) . '<a:x>'),
        );
        self::assertSame(
            '<p><a href="mailto:&quot;a&lt;b&quot;@example.com">"a&lt;b"@example.com</a></p>' . "\n",
            $converter->convert('<"a<b"@example.com>'),
        );
    }

    public function testNativeLinkParsingDoesNotCallTheOverridableLookahead(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            protected function findLinkDestinationEnd(string $text, int $pos): ?int
            {
                throw new LogicException('This override only handles emphasis lookahead');
            }
        };
        $paragraph = new Paragraph();
        $parser->parse($paragraph, '[x](a(b)c)');
        $children = $paragraph->getChildren();
        self::assertCount(1, $children);
        self::assertSame('a(b)c', $children[0]->getDestination());
    }

    public function testLongDollarRunsKeepOnlyTheFinalDisplayMathOpener(): void
    {
        $converter = DjotConverter::create();
        $document = $converter->parse(str_repeat('$', 8192) . '`x`');
        $children = $document->getChildren()[0]->getChildren();
        self::assertCount(8191, $children);
        $math = array_pop($children);
        $literal = '';
        foreach ($children as $child) {
            $literal .= $child->getContent();
        }
        self::assertSame(str_repeat('$', 8190), $literal);
        self::assertSame('x', $math->getContent());
        self::assertTrue($math->isDisplay());
    }

    public function testQuotedEmailSegmentsAndFinalUrlNewlineKeepTheirExistingMeaning(): void
    {
        $converter = DjotConverter::create();
        foreach (['"a".b@example.com', '"a"."b"@example.com', 'a."b<c"@example.com', 'a."b\\ c"@example.com'] as $email) {
            $document = $converter->parse('<' . $email . '>');
            $children = $document->getChildren()[0]->getChildren();
            self::assertCount(1, $children);
            self::assertInstanceOf(Link::class, $children[0]);
            self::assertSame('mailto:' . $email, $children[0]->getDestination());
        }
        $url = "https://example.com\n";
        $children = $converter->parse('<' . $url . '>')->getChildren()[0]->getChildren();
        self::assertCount(1, $children);
        self::assertInstanceOf(Link::class, $children[0]);
        self::assertSame($url, $children[0]->getDestination());
    }

    public function testInvalidNestedAttributesKeepInnerAttachmentAndComments(): void
    {
        $converter = DjotConverter::create();
        self::assertSame("<p>{a }</p>\n", $converter->convert('{a {a }}'));
        self::assertSame("<p><span a=\"\">word</span> %}</p>\n", $converter->convert('word{a % {nested} %}'));
        self::assertSame("<p><span a=\"{%}\">word</span></p>\n", $converter->convert('word{a="{%}"}'));
        self::assertStringContainsString('<span a="">x</span>', $converter->convert(str_repeat('[x]{a ', 512) . str_repeat('}', 512)));
    }

    public function testAttributeProofPreservesSubclassValidation(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            protected function isValidAttrPayload(string $attrStr): bool
            {
                return true;
            }
        };
        $paragraph = new Paragraph();
        $parser->parse($paragraph, '[x]{a{b}}');
        self::assertInstanceOf(Span::class, $paragraph->getChildren()[0]);
    }
}
