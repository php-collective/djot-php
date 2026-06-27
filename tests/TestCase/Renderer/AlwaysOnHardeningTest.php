<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousCssProvider(): array
    {
        return [
            'expression' => ['width:expression(alert(1))'],
            'url' => ['background:url(javascript:alert(1))'],
            'import' => ['x:y;@import "evil.css"'],
            'behavior' => ['behavior:url(x.htc)'],
            'moz-binding' => ['-moz-binding:url(x.xml)'],
            'escaped-expression' => ['width:expr\\65 ssion(alert(1))'],
        ];
    }

    #[DataProvider('dangerousCssProvider')]
    public function testDangerousCssStyleBlanked(string $css): void
    {
        $result = $this->converter->convert('text{style="' . $css . '"}');
        $this->assertStringContainsString('style=""', $result, "did not blank: $css");
    }

    public function testSafeCssStylePreserved(): void
    {
        $result = $this->converter->convert('text{style="color:red"}');
        $this->assertStringContainsString('style="color:red"', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousSchemeProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('dangerousSchemeProvider')]
    public function testDangerousLinkSchemesBlanked(string $url): void
    {
        $result = $this->converter->convert('[x](' . $url . ')');
        $this->assertStringContainsString('href=""', $result, "did not blank: $url");
    }

    public function testColonBearingNonSchemeValuePreserved(): void
    {
        // A value with a colon that is not a dangerous scheme must pass through.
        $result = $this->converter->convert('text{data-when="10:30"}');
        $this->assertStringContainsString('data-when="10:30"', $result);
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
