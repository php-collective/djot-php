<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML attribute names compare case-insensitively, so each is written once.
 */
class CaseFoldedAttributesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'id written twice' => ['[x]{id=a ID=b}', '<p><span id="b">x</span></p>'],
            'key written twice' => ['[x]{Data-K=a data-k=b}', '<p><span Data-K="b">x</span></p>'],
            'class joins' => ['[x]{.a CLASS="b"}', '<p><span class="a b">x</span></p>'],
            'derived href wins' => ['[x](http://ok){HREF="h"}', '<p><a href="http://ok">x</a></p>'],
            'derived href wins over its own spelling' => ['[x](http://ok){href="h"}', '<p><a href="http://ok">x</a></p>'],
            'derived image attributes win' => ['![a](s){SRC="t" ALT="b"}', '<p><img alt="a" src="s"></p>'],
            'math class merges' => ['$`x`{CLASS=k}', '<p><span class="math inline k">\(x\)</span></p>'],
        ];
    }

    #[DataProvider('documents')]
    public function testAttributeIsWrittenOnce(string $djot, string $expected): void
    {
        $this->assertSame($expected . "\n", (new DjotConverter())->convert($djot));
    }

    public function testUppercaseIdNamesTheSection(): void
    {
        $html = (new DjotConverter())->convert("{ID=sec}\n# H\n");

        $this->assertStringContainsString('<section id="sec">', $html);
        $this->assertStringContainsString('<h1>H</h1>', $html);
    }

    public function testDerivedOrderedListStartWins(): void
    {
        $html = (new DjotConverter())->convert("{START=9}\n3. a\n");

        $this->assertStringStartsWith("<ol start=\"3\">\n", $html);
    }
}
