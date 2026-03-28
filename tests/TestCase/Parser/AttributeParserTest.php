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
        // Use 'class="' to avoid false-fails if "class" appears in content
        $this->assertStringNotContainsString('class="', $result);
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

    /**
     * Tests for attribute source order preservation.
     *
     * The reference JS implementation preserves the order of attributes
     * as they appear in the source. This is important for consistent output.
     */
    public function testAttributeOrderKeyThenClass(): void
    {
        // key=val before .class - should preserve this order
        $result = $this->converter->convert('[text]{key=val .foo}');

        // key should appear before class in the output
        $keyPos = strpos($result, 'key="val"');
        $classPos = strpos($result, 'class="foo"');

        $this->assertNotFalse($keyPos);
        $this->assertNotFalse($classPos);
        $this->assertLessThan($classPos, $keyPos, 'key should appear before class');
    }

    public function testAttributeOrderClassThenKey(): void
    {
        // .class before key=val - should preserve this order
        $result = $this->converter->convert('[text]{.foo key=val}');

        // class should appear before key in the output
        $classPos = strpos($result, 'class="foo"');
        $keyPos = strpos($result, 'key="val"');

        $this->assertNotFalse($classPos);
        $this->assertNotFalse($keyPos);
        $this->assertLessThan($keyPos, $classPos, 'class should appear before key');
    }

    public function testAttributeOrderWithQuotedValue(): void
    {
        // Quoted value with dot, followed by class
        $result = $this->converter->convert('[text]{src="image.png" .thumbnail}');

        $srcPos = strpos($result, 'src="image.png"');
        $classPos = strpos($result, 'class="thumbnail"');

        $this->assertNotFalse($srcPos);
        $this->assertNotFalse($classPos);
        $this->assertLessThan($classPos, $srcPos, 'src should appear before class');
    }

    public function testAttributeOrderClassThenQuotedValue(): void
    {
        // Class before quoted value
        $result = $this->converter->convert('[text]{.thumbnail src="image.png"}');

        $classPos = strpos($result, 'class="thumbnail"');
        $srcPos = strpos($result, 'src="image.png"');

        $this->assertNotFalse($classPos);
        $this->assertNotFalse($srcPos);
        $this->assertLessThan($srcPos, $classPos, 'class should appear before src');
    }

    public function testAttributeOrderMultipleAttributes(): void
    {
        // Multiple attributes in specific order
        $result = $this->converter->convert('[text]{role=button .primary title="Click me"}');

        $rolePos = strpos($result, 'role="button"');
        $classPos = strpos($result, 'class="primary"');
        $titlePos = strpos($result, 'title="Click me"');

        $this->assertNotFalse($rolePos);
        $this->assertNotFalse($classPos);
        $this->assertNotFalse($titlePos);
        $this->assertLessThan($classPos, $rolePos, 'role should appear before class');
        $this->assertLessThan($titlePos, $classPos, 'class should appear before title');
    }

    public function testInvalidUnquotedValueDoesNotAffectOrder(): void
    {
        // Invalid unquoted value should be skipped, not affect ordering of valid attrs
        $result = $this->converter->convert('[text]{.myclass invalid=foo.bar data-x=valid}');

        $classPos = strpos($result, 'class="myclass"');
        $dataPos = strpos($result, 'data-x="valid"');

        $this->assertNotFalse($classPos);
        $this->assertNotFalse($dataPos);
        // invalid=foo.bar should be skipped entirely
        $this->assertStringNotContainsString('invalid=', $result);
        $this->assertLessThan($dataPos, $classPos, 'class should appear before data-x');
    }

    /**
     * Tests for comment handling in attributes.
     *
     * Djot supports two comment styles in attributes:
     * - Inline: % comment % (removed entirely)
     * - Trailing: % to end of string (removed)
     */
    public function testInlineCommentIsRemoved(): void
    {
        $result = AttributeParser::parse('.class % this is a comment %');

        $this->assertSame('class', $result['class']);
        $this->assertArrayNotHasKey('this', $result);
        $this->assertArrayNotHasKey('is', $result);
        $this->assertArrayNotHasKey('a', $result);
        $this->assertArrayNotHasKey('comment', $result);
    }

    public function testTrailingCommentIsRemoved(): void
    {
        $result = AttributeParser::parse('.class % trailing comment');

        $this->assertSame('class', $result['class']);
        $this->assertArrayNotHasKey('trailing', $result);
        $this->assertArrayNotHasKey('comment', $result);
    }

    public function testInlineCommentBetweenAttributes(): void
    {
        $result = AttributeParser::parse('.foo % inline comment % .bar');

        $this->assertSame('foo bar', $result['class']);
        $this->assertArrayNotHasKey('inline', $result);
        $this->assertArrayNotHasKey('comment', $result);
    }

    public function testCommentOnlyAttributeBlock(): void
    {
        $result = AttributeParser::parse('% just a comment %');

        $this->assertEmpty($result);
    }

    public function testCommentWithKeyValue(): void
    {
        $result = AttributeParser::parse('key=val % comment % .class');

        $this->assertSame('val', $result['key']);
        $this->assertSame('class', $result['class']);
        $this->assertArrayNotHasKey('comment', $result);
    }

    public function testCommentInConvertedOutput(): void
    {
        $result = $this->converter->convert('[text]{.class % this is a comment %}');

        $this->assertStringContainsString('class="class"', $result);
        $this->assertStringNotContainsString('this', $result);
        $this->assertStringNotContainsString('comment', $result);
    }

    /**
     * Tests for percent signs inside quoted values.
     *
     * Percent signs are used as comment markers in djot attributes,
     * but when they appear inside quoted strings, they should be
     * treated as literal characters, not comment markers.
     */
    public function testPercentInDoubleQuotedValue(): void
    {
        $result = AttributeParser::parse('title="100% done"');

        $this->assertSame('100% done', $result['title']);
    }

    public function testPercentAtEndOfDoubleQuotedValue(): void
    {
        $result = AttributeParser::parse('title="100%"');

        $this->assertSame('100%', $result['title']);
    }

    public function testPercentInSingleQuotedValue(): void
    {
        $result = AttributeParser::parse("title='50% off'");

        $this->assertSame('50% off', $result['title']);
    }

    public function testPercentInQuotedValueWithClass(): void
    {
        $result = AttributeParser::parse('.class title="50% off"');

        $this->assertSame('class', $result['class']);
        $this->assertSame('50% off', $result['title']);
    }

    public function testMultiplePercentsInQuotedValue(): void
    {
        $result = AttributeParser::parse('desc="10% to 20% discount"');

        $this->assertSame('10% to 20% discount', $result['desc']);
    }

    public function testPercentInQuotedValueFollowedByComment(): void
    {
        // The % inside quotes is literal, the % outside starts a comment
        $result = AttributeParser::parse('title="100% done" % this is a comment');

        $this->assertSame('100% done', $result['title']);
        $this->assertArrayNotHasKey('this', $result);
    }

    public function testPercentInQuotedValueInConvertedOutput(): void
    {
        $result = $this->converter->convert('[text]{title="100% done"}');

        $this->assertStringContainsString('title="100% done"', $result);
    }

    /**
     * Tests for curly braces inside quoted attribute values.
     *
     * Curly braces inside quoted strings should be treated as literal
     * characters, not as attribute block delimiters.
     */
    public function testCurlyBraceInDoubleQuotedValue(): void
    {
        $result = $this->converter->convert('[text]{code="{foo}"}');

        $this->assertStringContainsString('code="{foo}"', $result);
    }

    public function testCurlyBraceInSingleQuotedValue(): void
    {
        $result = $this->converter->convert("[text]{code='{bar}'}");

        $this->assertStringContainsString('code="{bar}"', $result);
    }

    public function testCurlyBraceInLinkAttributes(): void
    {
        $result = $this->converter->convert('[link](http://example.com){data="{json}"}');

        $this->assertStringContainsString('data="{json}"', $result);
    }

    public function testMultipleCurlyBracesInValue(): void
    {
        $result = $this->converter->convert('[text]{template="{{name}}"}');

        $this->assertStringContainsString('template="{{name}}"', $result);
    }

    /**
     * Tests for colon in attribute keys (xml:lang, data:foo, etc.).
     *
     * Colons should be allowed in attribute key names to support
     * namespaced attributes like xml:lang.
     */
    public function testColonInAttributeKey(): void
    {
        $result = AttributeParser::parse('xml:lang=en');

        $this->assertSame('en', $result['xml:lang']);
    }

    public function testColonInAttributeKeyQuoted(): void
    {
        $result = AttributeParser::parse('xmlns:xlink="http://example.com"');

        $this->assertSame('http://example.com', $result['xmlns:xlink']);
    }

    public function testColonInAttributeKeyConvertedOutput(): void
    {
        $result = $this->converter->convert('[text]{xml:lang=en}');

        $this->assertStringContainsString('xml:lang="en"', $result);
    }

    public function testUnderscoreKeyAfterWhitespace(): void
    {
        // Underscore-prefixed keys are valid after whitespace (matching JS reference)
        $result = $this->converter->convert('[text]{a=1 _b=2}');

        $this->assertStringContainsString('a="1"', $result);
        $this->assertStringContainsString('_b="2"', $result);
    }

    public function testHyphenKeyAfterWhitespace(): void
    {
        // Hyphen-prefixed keys are valid after whitespace (matching JS reference)
        $result = $this->converter->convert('[text]{a=1 -b=2}');

        $this->assertStringContainsString('a="1"', $result);
        $this->assertStringContainsString('-b="2"', $result);
    }

    /**
     * Tests for backslash escape handling in attribute values.
     *
     * Per djot spec, backslash escapes ASCII punctuation:
     * - \\ -> \ (escaped backslash)
     * - \" -> " (escaped quote)
     * - \* -> * (escaped asterisk)
     *
     * But NOT alphanumeric characters:
     * - \n remains \n (not newline)
     * - \t remains \t (not tab)
     */
    public function testBackslashBeforeLetterPreserved(): void
    {
        // \n and \t should remain literal (not processed as escapes)
        $result = AttributeParser::parse('desc="line1\\nline2"');

        $this->assertSame('line1\nline2', $result['desc']);
    }

    public function testDoubleBackslashEscaped(): void
    {
        // Double backslash should become single backslash
        $result = AttributeParser::parse('path="C:\\\\Users\\\\test"');

        $this->assertSame('C:\Users\test', $result['path']);
    }

    public function testEscapedQuoteInValue(): void
    {
        // Escaped quote should become literal quote
        $result = AttributeParser::parse('title="say \\"hello\\""');

        $this->assertSame('say "hello"', $result['title']);
    }

    public function testEscapedAsterisk(): void
    {
        // \* should become just *
        $result = AttributeParser::parse('key="\\*"');

        $this->assertSame('*', $result['key']);
    }

    public function testEscapedPunctuation(): void
    {
        // Various punctuation escapes
        $result = AttributeParser::parse('key="\\{\\}\\[\\]"');

        $this->assertSame('{}[]', $result['key']);
    }

    public function testMixedEscapes(): void
    {
        // \\\* = \\ + * (after escape processing) = \*
        $result = AttributeParser::parse('key="\\\\\\*"');

        $this->assertSame('\*', $result['key']);
    }

    /**
     * Tests for multiple consecutive attribute blocks.
     *
     * Per djot spec, multiple consecutive attribute blocks like {.foo}{.bar}
     * should merge. Classes combine, later values override earlier ones.
     */
    public function testMultipleAttributeBlocksOnSpan(): void
    {
        $result = $this->converter->convert('[text]{.foo}{.bar}');

        $this->assertStringContainsString('class="foo bar"', $result);
    }

    public function testMultipleAttributeBlocksClassAndId(): void
    {
        $result = $this->converter->convert('[text]{.foo}{#myid}');

        $this->assertStringContainsString('class="foo"', $result);
        $this->assertStringContainsString('id="myid"', $result);
    }

    public function testMultipleAttributeBlocksIdOverride(): void
    {
        // Later id should override earlier one
        $result = $this->converter->convert('[text]{#id1}{#id2}');

        $this->assertStringContainsString('id="id2"', $result);
        $this->assertStringNotContainsString('id="id1"', $result);
    }

    public function testMultipleAttributeBlocksKeyOverride(): void
    {
        // Later key=value should override earlier one
        $result = $this->converter->convert('[text]{key=a}{key=b}');

        $this->assertStringContainsString('key="b"', $result);
        $this->assertStringNotContainsString('key="a"', $result);
    }

    public function testThreeConsecutiveClassAttributes(): void
    {
        $result = $this->converter->convert('[text]{.a}{.b}{.c}');

        $this->assertStringContainsString('class="a b c"', $result);
    }

    public function testMultipleAttributeBlocksOnLink(): void
    {
        $result = $this->converter->convert('[link](http://example.com){.foo}{.bar}');

        $this->assertStringContainsString('class="foo bar"', $result);
        $this->assertStringContainsString('href="http://example.com"', $result);
    }

    public function testMultipleAttributeBlocksOnStrong(): void
    {
        $result = $this->converter->convert('*bold*{.foo}{.bar}');

        $this->assertStringContainsString('<strong class="foo bar">bold</strong>', $result);
    }

    public function testMultipleAttributeBlocksOnEmphasis(): void
    {
        $result = $this->converter->convert('_italic_{.foo}{.bar}');

        $this->assertStringContainsString('<em class="foo bar">italic</em>', $result);
    }

    public function testMultipleAttributeBlocksOnCode(): void
    {
        $result = $this->converter->convert('`code`{.foo}{.bar}');

        $this->assertStringContainsString('<code class="foo bar">code</code>', $result);
    }

    public function testMultipleAttributeBlocksOnHighlight(): void
    {
        $result = $this->converter->convert('{=highlight=}{.foo}{.bar}');

        $this->assertStringContainsString('<mark class="foo bar">highlight</mark>', $result);
    }

    /**
     * Tests for autolink attributes.
     *
     * Per djot spec, autolinks (<url> or <email>) can have trailing attributes.
     */
    public function testAutolinkWithClass(): void
    {
        $result = $this->converter->convert('<https://example.com>{.link}');

        $this->assertStringContainsString('class="link"', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testAutolinkWithId(): void
    {
        $result = $this->converter->convert('<https://example.com>{#myid}');

        $this->assertStringContainsString('id="myid"', $result);
    }

    public function testAutolinkWithKeyValue(): void
    {
        $result = $this->converter->convert('<https://example.com>{target=_blank}');

        $this->assertStringContainsString('target="_blank"', $result);
    }

    public function testEmailAutolinkWithClass(): void
    {
        $result = $this->converter->convert('<user@example.com>{.email}');

        $this->assertStringContainsString('class="email"', $result);
        $this->assertStringContainsString('href="mailto:user@example.com"', $result);
    }

    public function testAutolinkWithMultipleAttributeBlocks(): void
    {
        $result = $this->converter->convert('<https://example.com>{.foo}{.bar}');

        $this->assertStringContainsString('class="foo bar"', $result);
    }
}
