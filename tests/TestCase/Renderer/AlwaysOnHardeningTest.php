<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Security hardening that applies to the DEFAULT renderer (no safe mode set).
 *
 * Dangerous URL schemes, event-handler / injection-sink attributes, CSS
 * `expression()` and unbounded inline nesting must be neutralized even when the
 * caller never opts into safe mode. Safe mode (tested in {@see \Djot\Test\SafeModeTest})
 * layers stricter filtering on top.
 */
class AlwaysOnHardeningTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        // No safe mode configured: this is the default converter.
        $this->converter = new DjotConverter();
    }

    public function testDangerousLinkSchemeBlankedWithoutSafeMode(): void
    {
        $result = $this->converter->convert('[x](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('<a href="">x</a>', $result);
    }

    public function testDangerousImageSchemeBlankedWithoutSafeMode(): void
    {
        $result = $this->converter->convert('![a](vbscript:msgbox(1))');
        $this->assertStringNotContainsString('vbscript:', $result);
    }

    public function testSchemeEvasionWithControlCharIsBlanked(): void
    {
        // `java\tscript:` must not slip past the denylist.
        $result = $this->converter->convert("[x](java\tscript:alert(1))");
        $this->assertStringNotContainsString('script:', $result);
    }

    public function testSafeSchemeAndRelativeUrlPreserved(): void
    {
        $this->assertStringContainsString(
            'href="https://ok.example"',
            $this->converter->convert('[x](https://ok.example)'),
        );
        $this->assertStringContainsString(
            'href="/local/page"',
            $this->converter->convert('[x](/local/page)'),
        );
    }

    public function testEventHandlerAttributeStrippedWithoutSafeMode(): void
    {
        $result = $this->converter->convert('[x](https://a){onclick="alert(1)"}');
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function testInjectionSinkAttributesStrippedWithoutSafeMode(): void
    {
        $result = $this->converter->convert('text{srcdoc="x" formaction="y"}');
        $this->assertStringNotContainsString('srcdoc', $result);
        $this->assertStringNotContainsString('formaction', $result);
    }

    public function testCssExpressionBlankedWithoutSafeMode(): void
    {
        $result = $this->converter->convert('text{style="x:expression(alert(1))"}');
        $this->assertStringNotContainsString('expression(', $result);
    }

    public function testDangerousAttributeValueSchemeBlanked(): void
    {
        // A non-href attribute may still carry a javascript: payload.
        $result = $this->converter->convert('text{data-x="javascript:alert(1)"}');
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testDeeplyNestedInlineDoesNotBlowUp(): void
    {
        $depth = 4000;
        $bomb = str_repeat('[', $depth) . 'x' . str_repeat('](u)', $depth);

        $start = microtime(true);
        $result = $this->converter->convert($bomb);
        $elapsed = microtime(true) - $start;

        // Without the cap this is ~O(n^2) (seconds); the guard keeps it fast.
        $this->assertLessThan(2.0, $elapsed, 'inline nesting DoS guard did not bound parse time');
        $this->assertNotSame('', $result);
    }
}
