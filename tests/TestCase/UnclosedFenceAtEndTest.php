<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The newline that ends the document's last line is not an extra empty line,
 * so an unclosed code fence at the end keeps exactly its content (matches
 * djot.js).
 */
class UnclosedFenceAtEndTest extends TestCase
{
    #[DataProvider('casesProvider')]
    public function testUnclosedFence(string $djot, string $expected): void
    {
        $this->assertSame($expected, (new DjotConverter())->convert($djot));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casesProvider(): array
    {
        return [
            'empty' => [
                "```\n",
                "<pre><code></code></pre>\n",
            ],
            'one line' => [
                "```\nx\n",
                "<pre><code>x\n</code></pre>\n",
            ],
            'one blank line' => [
                "```\n\n",
                "<pre><code>\n</code></pre>\n",
            ],
            'with language' => [
                "~~~ php\nx\n",
                "<pre><code class=\"language-php\">x\n</code></pre>\n",
            ],
            'no final newline' => [
                "```\nx\n```",
                "<pre><code>x\n</code></pre>\n",
            ],
        ];
    }
}
