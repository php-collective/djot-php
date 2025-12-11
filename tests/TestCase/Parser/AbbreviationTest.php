<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Node\Inline\Abbreviation;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AbbreviationTest extends TestCase
{
    protected BlockParser $parser;

    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
        $this->converter = new DjotConverter();
    }

    public function testSimpleAbbreviation(): void
    {
        $input = <<<'DJOT'
The HTML specification is maintained by the W3C.

*[HTML]: Hyper Text Markup Language
*[W3C]: World Wide Web Consortium
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr>',
            $html,
        );
        $this->assertStringContainsString(
            '<abbr title="World Wide Web Consortium">W3C</abbr>',
            $html,
        );
    }

    public function testAbbreviationExtraction(): void
    {
        $input = <<<'DJOT'
Some text

*[HTML]: Hyper Text Markup Language
*[CSS]: Cascading Style Sheets
DJOT;

        $this->parser->parse($input);
        $abbreviations = $this->parser->getAbbreviations();

        $this->assertArrayHasKey('HTML', $abbreviations);
        $this->assertArrayHasKey('CSS', $abbreviations);
        $this->assertSame('Hyper Text Markup Language', $abbreviations['HTML']);
        $this->assertSame('Cascading Style Sheets', $abbreviations['CSS']);
    }

    public function testAbbreviationNotRenderedInOutput(): void
    {
        $input = <<<'DJOT'
The HTML spec.

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // The definition line should not appear in output
        $this->assertStringNotContainsString('*[HTML]', $html);
        $this->assertStringNotContainsString('Hyper Text Markup Language</p>', $html);
    }

    public function testMultipleOccurrences(): void
    {
        $input = <<<'DJOT'
HTML is great. I love HTML. HTML forever!

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // Should have 3 occurrences of the abbr tag
        $this->assertSame(3, substr_count($html, '<abbr title="Hyper Text Markup Language">HTML</abbr>'));
    }

    public function testAbbreviationWordBoundary(): void
    {
        $input = <<<'DJOT'
HTML is not the same as HTMLElement or XHTML.

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // Only standalone "HTML" should be wrapped
        $this->assertStringContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr> is not', $html);
        // HTMLElement and XHTML should NOT be wrapped
        $this->assertStringContainsString('HTMLElement', $html);
        $this->assertStringContainsString('XHTML', $html);
        $this->assertStringNotContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr>Element', $html);
    }

    public function testAbbreviationCaseSensitive(): void
    {
        $input = <<<'DJOT'
HTML and html are different.

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // Only uppercase HTML should be wrapped
        $this->assertStringContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr>', $html);
        // Lowercase html should not be wrapped
        $this->assertStringContainsString(' html ', $html);
        $this->assertStringNotContainsString('<abbr title="Hyper Text Markup Language">html</abbr>', $html);
    }

    public function testAbbreviationInHeading(): void
    {
        $input = <<<'DJOT'
# Understanding HTML

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr>',
            $html,
        );
    }

    public function testAbbreviationInList(): void
    {
        $input = <<<'DJOT'
- HTML basics
- CSS fundamentals

*[HTML]: Hyper Text Markup Language
*[CSS]: Cascading Style Sheets
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr>', $html);
        $this->assertStringContainsString('<abbr title="Cascading Style Sheets">CSS</abbr>', $html);
    }

    public function testAbbreviationInBlockquote(): void
    {
        $input = <<<'DJOT'
> HTML is the foundation of the web.

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr>', $html);
    }

    public function testMultiWordAbbreviation(): void
    {
        $input = <<<'DJOT'
The US is a country.

*[US]: United States
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString('<abbr title="United States">US</abbr>', $html);
    }

    public function testAbbreviationDefinitionAtStart(): void
    {
        $input = <<<'DJOT'
*[HTML]: Hyper Text Markup Language

Learn HTML today!
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString('<abbr title="Hyper Text Markup Language">HTML</abbr>', $html);
    }

    public function testAbbreviationDefinitionMultiline(): void
    {
        $input = <<<'DJOT'
The HTML spec is complex.

*[HTML]: Hyper Text Markup Language,
  the standard markup language for documents
  designed to be displayed in a web browser
DJOT;

        $this->parser->parse($input);
        $abbreviations = $this->parser->getAbbreviations();

        $this->assertArrayHasKey('HTML', $abbreviations);
        $this->assertStringContainsString('the standard markup language', $abbreviations['HTML']);
    }

    public function testNoAbbreviationsDefined(): void
    {
        $input = 'Just regular text with HTML mentioned.';

        $html = $this->converter->convert($input);

        // No abbr tags should be present
        $this->assertStringNotContainsString('<abbr', $html);
        $this->assertStringContainsString('HTML', $html);
    }

    public function testAbbreviationNode(): void
    {
        $abbr = new Abbreviation('Test Definition');

        $this->assertSame('abbreviation', $abbr->getType());
        $this->assertSame('Test Definition', $abbr->getTitle());

        $abbr->setTitle('New Definition');
        $this->assertSame('New Definition', $abbr->getTitle());
    }

    public function testLongerAbbreviationMatchedFirst(): void
    {
        // When one abbreviation contains another, the longer one should match
        $input = <<<'DJOT'
Both JS and JavaScript are popular.

*[JS]: JavaScript (short)
*[JavaScript]: A programming language
DJOT;

        $html = $this->converter->convert($input);

        // "JavaScript" should use its own definition, not be split
        $this->assertStringContainsString('<abbr title="A programming language">JavaScript</abbr>', $html);
        $this->assertStringContainsString('<abbr title="JavaScript (short)">JS</abbr>', $html);
    }

    public function testInlineSpanAbbreviationTakesPrecedence(): void
    {
        // Test that inline [HTML]{abbr="..."} style abbreviations work alongside
        // definition-based *[HTML]: ... abbreviations
        // The inline span approach uses the abbr attribute on a span, which is
        // different from the definition-based approach that processes plain text

        $input = <<<'DJOT'
Regular HTML text and [HTML]{abbr="Custom Inline Definition"} with attribute.

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // The inline span-based text gets wrapped in a span with the abbr attribute
        // The span content "HTML" is still processed for abbreviations
        $this->assertStringContainsString('abbr="Custom Inline Definition"', $html);

        // The regular text "HTML" should have the definition-based abbr
        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr> text',
            $html,
        );
    }

    public function testAbbreviationDoesNotAffectInlineSpans(): void
    {
        // Ensure that text inside spans with other attributes is still processed
        $input = <<<'DJOT'
[Learn HTML today]{.highlight}

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        // The span should contain the abbreviation-wrapped HTML
        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr>',
            $html,
        );
        $this->assertStringContainsString('class="highlight"', $html);
    }

    public function testAbbreviationDoesNotAffectEmphasis(): void
    {
        // Text inside emphasis should still have abbreviations applied
        $input = <<<'DJOT'
_HTML_ is *important*

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString(
            '<em><abbr title="Hyper Text Markup Language">HTML</abbr></em>',
            $html,
        );
    }

    public function testAbbreviationInLink(): void
    {
        // Text inside links should still have abbreviations applied
        $input = <<<'DJOT'
[Learn HTML](https://example.com)

*[HTML]: Hyper Text Markup Language
DJOT;

        $html = $this->converter->convert($input);

        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr>',
            $html,
        );
        $this->assertStringContainsString('href="https://example.com"', $html);
    }
}
