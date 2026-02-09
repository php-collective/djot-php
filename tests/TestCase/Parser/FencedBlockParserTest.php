<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\Parser\Block\FencedBlockParser;
use PHPUnit\Framework\TestCase;

class FencedBlockParserTest extends TestCase
{
    protected FencedBlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FencedBlockParser();
    }

    public function testParseCodeBlockInfoLanguageOnly(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php');

        $this->assertSame('php', $result['language']);
        $this->assertFalse($result['showLineNumbers']);
        $this->assertSame(1, $result['lineNumberStart']);
        $this->assertSame([], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoEmpty(): void
    {
        $result = $this->parser->parseCodeBlockInfo('');

        $this->assertNull($result['language']);
        $this->assertFalse($result['showLineNumbers']);
        $this->assertSame(1, $result['lineNumberStart']);
        $this->assertSame([], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoLineNumbers(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php #');

        $this->assertSame('php', $result['language']);
        $this->assertTrue($result['showLineNumbers']);
        $this->assertSame(1, $result['lineNumberStart']);
    }

    public function testParseCodeBlockInfoLineNumbersWithOffset(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php #=5');

        $this->assertSame('php', $result['language']);
        $this->assertTrue($result['showLineNumbers']);
        $this->assertSame(5, $result['lineNumberStart']);
    }

    public function testParseCodeBlockInfoHighlightLines(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php {2,4-6}');

        $this->assertSame('php', $result['language']);
        $this->assertFalse($result['showLineNumbers']);
        $this->assertSame([2, 4, 5, 6], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoLineNumbersAndHighlight(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php # {2,4}');

        $this->assertSame('php', $result['language']);
        $this->assertTrue($result['showLineNumbers']);
        $this->assertSame([2, 4], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoLineNumbersOffsetAndHighlight(): void
    {
        $result = $this->parser->parseCodeBlockInfo('php #=10 {2,4}');

        $this->assertSame('php', $result['language']);
        $this->assertTrue($result['showLineNumbers']);
        $this->assertSame(10, $result['lineNumberStart']);
        $this->assertSame([2, 4], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoLineNumbersOnlyNoLanguage(): void
    {
        $result = $this->parser->parseCodeBlockInfo('#');

        $this->assertNull($result['language']);
        $this->assertTrue($result['showLineNumbers']);
        $this->assertSame(1, $result['lineNumberStart']);
    }

    public function testParseCodeBlockInfoHighlightOnlyNoLanguage(): void
    {
        $result = $this->parser->parseCodeBlockInfo('{1,3}');

        $this->assertNull($result['language']);
        $this->assertFalse($result['showLineNumbers']);
        $this->assertSame([1, 3], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoComplexLanguages(): void
    {
        // Test various language identifiers
        $languages = ['c++', 'c#', 'f#', '.net', 'node.js'];

        foreach ($languages as $lang) {
            $result = $this->parser->parseCodeBlockInfo($lang);
            $this->assertSame($lang, $result['language'], "Failed for language: $lang");
        }
    }

    public function testParseCodeBlockInfoHighlightRanges(): void
    {
        $result = $this->parser->parseCodeBlockInfo('{1-3, 5, 7-9}');

        $this->assertSame([1, 2, 3, 5, 7, 8, 9], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoHighlightDuplicatesRemoved(): void
    {
        $result = $this->parser->parseCodeBlockInfo('{1,1,2,2-4}');

        // Duplicates should be removed and sorted
        $this->assertSame([1, 2, 3, 4], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoInvalidHighlightIgnored(): void
    {
        // Invalid values like 0 or negative should be ignored
        $result = $this->parser->parseCodeBlockInfo('{0,1,-5,3}');

        $this->assertSame([1, 3], $result['highlightLines']);
    }

    public function testParseCodeBlockInfoBackwardsCompatibility(): void
    {
        // Info strings that don't match our pattern should be treated as language
        $result = $this->parser->parseCodeBlockInfo('some weird info string');

        $this->assertSame('some weird info string', $result['language']);
        $this->assertFalse($result['showLineNumbers']);
        $this->assertSame([], $result['highlightLines']);
    }
}
