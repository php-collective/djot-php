<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\SemanticSpanExtension;
use PHPUnit\Framework\TestCase;

class SemanticSpanExtensionTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        $this->converter->addExtension(new SemanticSpanExtension());
    }

    public function testKbdAttribute(): void
    {
        $html = $this->converter->convert('[Ctrl+C]{kbd}');

        $this->assertStringContainsString('<kbd>Ctrl+C</kbd>', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function testKbdAttributeWithEmptyValue(): void
    {
        $html = $this->converter->convert('[Ctrl+V]{kbd=""}');

        $this->assertStringContainsString('<kbd>Ctrl+V</kbd>', $html);
    }

    public function testAbbrAttribute(): void
    {
        $html = $this->converter->convert('[HTML]{abbr="HyperText Markup Language"}');

        $this->assertStringContainsString('<abbr title="HyperText Markup Language">HTML</abbr>', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function testDfnAttribute(): void
    {
        $html = $this->converter->convert('[term]{dfn}');

        $this->assertStringContainsString('<dfn>term</dfn>', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function testDfnAttributeWithTitle(): void
    {
        $html = $this->converter->convert('[API]{dfn="Application Programming Interface"}');

        $this->assertStringContainsString('<dfn title="Application Programming Interface">API</dfn>', $html);
    }

    public function testCombinedDfnAndAbbr(): void
    {
        $html = $this->converter->convert('[CSS]{dfn abbr="Cascading Style Sheets"}');

        // dfn should wrap abbr
        $this->assertStringContainsString(
            '<dfn><abbr title="Cascading Style Sheets">CSS</abbr></dfn>',
            $html,
        );
    }

    public function testCombinedDfnAndKbd(): void
    {
        $html = $this->converter->convert('[Escape]{dfn="The escape key" kbd}');

        // dfn should wrap kbd
        $this->assertStringContainsString(
            '<dfn title="The escape key"><kbd>Escape</kbd></dfn>',
            $html,
        );
    }

    public function testPreservesOtherAttributes(): void
    {
        $html = $this->converter->convert('[Ctrl+C]{kbd .shortcut #copy-shortcut}');

        $this->assertStringContainsString('<span class="shortcut" id="copy-shortcut"><kbd>Ctrl+C</kbd></span>', $html);
    }

    public function testPreservesClassWithAbbr(): void
    {
        $html = $this->converter->convert('[HTML]{abbr="HyperText Markup Language" .tech-term}');

        $this->assertStringContainsString('class="tech-term"', $html);
        $this->assertStringContainsString('<abbr title="HyperText Markup Language">HTML</abbr>', $html);
    }

    public function testRegularSpanUnaffected(): void
    {
        $html = $this->converter->convert('[text]{.highlight}');

        $this->assertStringContainsString('<span class="highlight">text</span>', $html);
        $this->assertStringNotContainsString('<kbd>', $html);
        $this->assertStringNotContainsString('<abbr>', $html);
        $this->assertStringNotContainsString('<dfn>', $html);
    }

    public function testNestedInlineContent(): void
    {
        $html = $this->converter->convert('[*Ctrl*+C]{kbd}');

        $this->assertStringContainsString('<kbd><strong>Ctrl</strong>+C</kbd>', $html);
    }

    public function testKbdInParagraph(): void
    {
        $html = $this->converter->convert('Press [Ctrl+S]{kbd} to save.');

        $this->assertStringContainsString('<p>Press <kbd>Ctrl+S</kbd> to save.</p>', $html);
    }

    public function testMultipleKbdInSameParagraph(): void
    {
        $html = $this->converter->convert('Use [Ctrl+C]{kbd} to copy and [Ctrl+V]{kbd} to paste.');

        $this->assertStringContainsString('<kbd>Ctrl+C</kbd>', $html);
        $this->assertStringContainsString('<kbd>Ctrl+V</kbd>', $html);
    }

    public function testAbbrTitleEscapesHtml(): void
    {
        $html = $this->converter->convert('[test]{abbr="<script>alert(1)</script>"}');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testDfnTitleEscapesHtml(): void
    {
        $html = $this->converter->convert('[test]{dfn="contains \"quotes\""}');

        $this->assertStringContainsString('contains &quot;quotes&quot;', $html);
    }

    public function testAllThreeCombined(): void
    {
        // This is an edge case - all three attributes
        $html = $this->converter->convert('[HTML]{dfn kbd abbr="HyperText Markup Language"}');

        // Order: dfn wraps kbd wraps abbr
        $this->assertStringContainsString('<dfn><kbd><abbr title="HyperText Markup Language">HTML</abbr></kbd></dfn>', $html);
    }

    public function testSampAttribute(): void
    {
        $html = $this->converter->convert('[Hello World]{samp}');

        $this->assertStringContainsString('<samp>Hello World</samp>', $html);
        $this->assertStringNotContainsString('<span samp="">', $html);
    }

    public function testVarAttribute(): void
    {
        $html = $this->converter->convert('[x]{var}');

        $this->assertStringContainsString('<var>x</var>', $html);
        $this->assertStringNotContainsString('<span var="">', $html);
    }

    public function testCombinedSampAndVar(): void
    {
        $html = $this->converter->convert('[value]{samp var}');

        $this->assertStringContainsString('<var><samp>value</samp></var>', $html);
    }

    public function testWithoutExtension(): void
    {
        $converter = new DjotConverter();
        // No extension added

        $html = $converter->convert('[Ctrl+C]{kbd}');

        // Should render as span with kbd attribute
        $this->assertStringContainsString('<span kbd="">Ctrl+C</span>', $html);
        $this->assertStringNotContainsString('<kbd>', $html);
    }
}
