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

    /**
     * Tests for unquoted attribute value validation.
     *
     * Per djot spec, unquoted values may only contain:
     * ASCII alphanumeric characters, underscore (_), colon (:), or hyphen (-)
     *
     * Invalid characters like dots, slashes, etc. should cause the attribute
     * to not be recognized (matching the reference JS implementation behavior).
     */
    public function testValidUnquotedValueWithUnderscore(): void
    {
        $result = AttributeParser::parse('key=foo_bar');

        $this->assertSame('foo_bar', $result['key']);
    }

    public function testValidUnquotedValueWithHyphen(): void
    {
        $result = AttributeParser::parse('key=foo-bar');

        $this->assertSame('foo-bar', $result['key']);
    }

    public function testValidUnquotedValueWithColon(): void
    {
        $result = AttributeParser::parse('key=foo:bar');

        $this->assertSame('foo:bar', $result['key']);
    }

    public function testValidUnquotedValueWithNumbers(): void
    {
        $result = AttributeParser::parse('key=abc123');

        $this->assertSame('abc123', $result['key']);
    }

    public function testInvalidUnquotedValueWithDotIsNotParsed(): void
    {
        // Dots are not allowed in unquoted values per djot spec
        // The attribute should not be recognized
        $result = AttributeParser::parse('key=foo.bar');

        $this->assertArrayNotHasKey('key', $result);
        // And importantly, .bar should NOT become a class
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testInvalidUnquotedValueWithSlashIsNotParsed(): void
    {
        $result = AttributeParser::parse('key=foo/bar');

        $this->assertArrayNotHasKey('key', $result);
    }

    public function testInvalidUnquotedValueWithAtSignIsNotParsed(): void
    {
        $result = AttributeParser::parse('key=foo@bar');

        $this->assertArrayNotHasKey('key', $result);
    }

    public function testDottedValueWorksWhenQuoted(): void
    {
        // Same value works fine when properly quoted
        $result = AttributeParser::parse('key="foo.bar"');

        $this->assertSame('foo.bar', $result['key']);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testMixedValidAndInvalidUnquotedValues(): void
    {
        // valid=good should be parsed, invalid=bad.value should not
        $result = AttributeParser::parse('valid=good invalid=bad.value');

        $this->assertSame('good', $result['valid']);
        $this->assertArrayNotHasKey('invalid', $result);
        $this->assertArrayNotHasKey('class', $result);
    }

    public function testRealClassWithInvalidUnquotedValue(): void
    {
        // .myclass should work, but key=foo.bar should not create spurious class
        $result = AttributeParser::parse('.myclass key=foo.bar');

        $this->assertSame('myclass', $result['class']);
        $this->assertArrayNotHasKey('key', $result);
    }
}
