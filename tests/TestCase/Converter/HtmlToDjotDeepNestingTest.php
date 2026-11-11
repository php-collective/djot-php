<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlToDjotDeepNestingTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function deepDocuments(): array
    {
        return [
            'divs past the libxml limit' => [
                '<p>before</p>' . str_repeat('<div>', 300) . '<p>deep</p>' . str_repeat('</div>', 300) . '<p>after</p>',
            ],
            'divs far past the libxml limit' => [
                '<p>before</p>' . str_repeat('<div>', 5000) . '<p>deep</p>' . str_repeat('</div>', 5000) . '<p>after</p>',
            ],
            'inline emphasis past the libxml limit' => [
                '<p>before</p><p>x ' . str_repeat('<em>', 300) . 'deep' . str_repeat('</em>', 300) . ' y</p><p>after</p>',
            ],
            'unclosed nesting past the libxml limit' => [
                '<p>before</p>' . str_repeat('<blockquote>', 400) . '<p>deep</p><p>after</p>',
            ],
        ];
    }

    #[DataProvider('deepDocuments')]
    public function testContentAfterDeepNestingIsKept(string $html): void
    {
        $djot = (new HtmlToDjot())->convert($html);

        $this->assertStringContainsString('before', $djot);
        $this->assertMatchesRegularExpression('/(?<![a-z])deep(?![a-z])/', $djot);
        $this->assertMatchesRegularExpression('/(?<![a-z])after(?![a-z])/', $djot);
    }

    public function testShallowElementsKeepTheirMarkupNextToDeepNesting(): void
    {
        $html = '<p><strong>before</strong></p>' . str_repeat('<div>', 300) . 'deep' . str_repeat('</div>', 300) . '<p><em>after</em></p>';

        $djot = (new HtmlToDjot())->convert($html);

        $this->assertStringStartsWith("*before*\n\n", $djot);
        $this->assertStringEndsWith("_after_\n", $djot);
    }

    public function testEveryTopLevelElementIsConverted(): void
    {
        $this->assertSame("a\n\nb\n\nc\n", (new HtmlToDjot())->convert('<p>a</p><div>b</div><p>c</p>'));
    }

    public function testPlainDivKeepsItsBlockBoundary(): void
    {
        $this->assertSame("a\n\nb\n", (new HtmlToDjot())->convert('<section><div>a</div><p>b</p></section>'));
    }

    public function testFlattenedRawTextStaysText(): void
    {
        $inner = '<p><textarea><script>keep &amp; me</script></textarea></p>';
        $converter = new HtmlToDjot();

        $shallow = $converter->convert($inner);
        $deep = $converter->convert(str_repeat('<div>', 300) . $inner . str_repeat('</div>', 300));

        $this->assertSame($shallow, $deep);
        $this->assertStringContainsString('keep & me', $deep);
    }
}
