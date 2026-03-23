<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Node\Block\Div;
use Djot\Parser\Utility\AttributeParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AttributeParser, focused on ensuring dots and hashes
 * inside quoted attribute values are not misinterpreted as .class/#id shorthand.
 *
 * The reference JS implementation (jgm/djot) uses a state machine that
 * naturally handles this. The PHP regex-based parser needs to strip quoted
 * values before matching .class and #id patterns.
 */
class AttributeParserTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testDotInDoubleQuotedValueDoesNotCreateClass(): void
    {
        $result = AttributeParser::parse('include="note.dj"');

        $this->assertSame('note.dj', $result['include']);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testDotInSingleQuotedValueDoesNotCreateClass(): void
    {
        $result = AttributeParser::parse("path='file.dj'");

        $this->assertSame('file.dj', $result['path']);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testHashInQuotedValueDoesNotCreateId(): void
    {
        $result = AttributeParser::parse('url="page#section"');

        $this->assertSame('page#section', $result['url']);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function testMultipleAttributesWithDotsInValues(): void
    {
        $result = AttributeParser::parse('role="include" path="shared/note.dj"');

        $this->assertSame('include', $result['role']);
        $this->assertSame('shared/note.dj', $result['path']);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testMultipleDotsInQuotedValue(): void
    {
        $result = AttributeParser::parse('src="path/to/file.min.js"');

        $this->assertSame('path/to/file.min.js', $result['src']);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testClassShorthandWithDottedAttribute(): void
    {
        $result = AttributeParser::parse('.myclass data-file="test.txt"');

        $this->assertSame('myclass', $result['class']);
        $this->assertSame('test.txt', $result['data-file']);
    }

    public function testClassAndIdWithDottedAndHashedValues(): void
    {
        $result = AttributeParser::parse('.foo #bar href="page.html#anchor"');

        $this->assertSame('foo', $result['class']);
        $this->assertSame('bar', $result['id']);
        $this->assertSame('page.html#anchor', $result['href']);
    }

    public function testBlockDivWithDotInAttributeValue(): void
    {
        $doc = $this->converter->parse("{include=\"note.dj\"}\n:::\n:::\n");
        $children = $doc->getChildren();

        $this->assertCount(1, $children);
        $this->assertInstanceOf(Div::class, $children[0]);
        $this->assertSame('note.dj', $children[0]->getAttribute('include'));
        $this->assertNull($children[0]->getAttribute('class'));
    }

    public function testInlineSpanWithDotInAttributeValue(): void
    {
        $result = $this->converter->convert('[link]{ref="/guides/setup.html"}');

        $this->assertStringContainsString('ref="/guides/setup.html"', $result);
        $this->assertStringNotContainsString('class', $result);
    }
}
