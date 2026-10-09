<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Exception\ParseException;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Node;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferenceContainerRegressionTest extends TestCase
{
    public function testDeferredStateIsClearedAfterAParseException(): void
    {
        $parser = new BlockParser(strictMode: true);
        try {
            $parser->parse("[r]: /u\n\n```\nx");
            self::fail('An unclosed fence must throw in strict mode.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Unclosed code fence', $exception->getMessage());
        }
        self::assertFalse($parser->defersReferences());
        $paragraph = new Paragraph();
        $parser->getInlineParser()->parse($paragraph, '[x][r] ![i][r]');
        $children = $paragraph->getChildren();
        self::assertInstanceOf(Link::class, $children[0]);
        self::assertSame('/u', $children[0]->getDestination());
        self::assertInstanceOf(Image::class, $children[2]);
        self::assertSame('/u', $children[2]->getSource());
    }

    public function testDeferredWarningsRemainInSourceOrder(): void
    {
        $parser = new BlockParser(collectWarnings: true);
        $parser->parse("[x][r]\n\n{.a} b {.c}\n\n```\nopen\n\n[s]: /s\n");
        $warnings = $parser->getWarnings();
        self::assertCount(3, $warnings);
        self::assertSame("Undefined reference 'r'", $warnings[0]->getMessage());
        self::assertSame([1, 3, 5], array_map(static fn ($warning): int => $warning->getLine(), $warnings));
    }

    public function testCustomBlockCallbackRunsOnceWithTheConfiguredParser(): void
    {
        $parser = new BlockParser();
        $calls = 0;
        $parser->addBlockPattern(
            '/^CUSTOM$/',
            function (array $lines, int $start, Node $parent, BlockParser $callbackParser) use (&$calls, $parser): int {
                self::assertSame($parser, $callbackParser);
                $calls++;
                $callbackParser->parseBlockContent($parent, ['custom block']);

                return 1;
            },
        );
        $html = (new DjotConverter(parser: $parser))->convert("[r][]\n\nCUSTOM\n\n[r]: /u\n");
        self::assertSame(1, $calls);
        self::assertSame("<p><a href=\"/u\">r</a></p>\n<p>custom block</p>\n", $html);
    }

    #[DataProvider('underIndentedProvider')]
    public function testUnderIndentedContinuation(string $source, string $html): void
    {
        foreach (['', "[r][]\n\n"] as $prefix) {
            $suffix = $prefix === '' ? '' : "\n[r]: /u\n";
            $expected = $prefix === '' ? $html : "<p><a href=\"/u\">r</a></p>\n" . $html;
            self::assertSame($expected, (new DjotConverter())->convert($prefix . $source . $suffix));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function underIndentedProvider(): array
    {
        return [
            'bullet fence' => [
                "- ```\n  a\n b\n  ```\n",
                "<ul>\n<li>\n<pre><code>a\nb\n</code></pre>\n</li>\n</ul>\n",
            ],
            'ordered fence' => [
                "1. ```\n   a\n  b\n   ```\n",
                "<ol>\n<li>\n<pre><code>a\nb\n</code></pre>\n</li>\n</ol>\n",
            ],
            'ordered table' => [
                "1. | a |\n  | b |\n",
                "<ol>\n<li>\n<table>\n<tr>\n<td>a</td>\n</tr>\n<tr>\n<td>b</td>\n</tr>\n</table>\n</li>\n</ol>\n",
            ],
        ];
    }

    #[DataProvider('regressionProvider')]
    public function testReferenceContainerRegression(string $source, string $html, string $djotJsHtml): void
    {
        foreach ([new DjotConverter(), new DjotConverter(parser: new BlockParser())] as $converter) {
            $actual = $converter->convert($source);
            self::assertSame($html, $actual);
            self::assertSame(
                preg_match('/<a href="\/u">r<\/a>/', $djotJsHtml),
                preg_match('/<a href="\/u">r<\/a>/', $actual),
            );
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function regressionProvider(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixture/Parser/reference-container-regressions.json');
        self::assertNotFalse($json);
        /** @var list<array{id:string, source:string, html:string, djotJsHtml:string}> $fixtures */
        $fixtures = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures as $fixture) {
            $cases[$fixture['id']] = [$fixture['source'], $fixture['html'], $fixture['djotJsHtml']];
        }

        return $cases;
    }
}
