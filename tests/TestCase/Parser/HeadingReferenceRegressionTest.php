<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeadingReferenceRegressionTest extends TestCase
{
    #[DataProvider('headingReferenceProvider')]
    public function testHeadingReferencesMatchDjotJs(string $source, string $html): void
    {
        foreach ([new DjotConverter(), new DjotConverter(parser: new BlockParser(collectWarnings: true))] as $converter) {
            self::assertSame($this->normalize($html), $this->normalize($converter->convert($source)));
            foreach ($converter->getWarnings() as $warning) {
                self::assertStringNotContainsString('Undefined reference', $warning->getMessage());
                self::assertStringNotContainsString('Broken anchor', $warning->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function headingReferenceProvider(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixture/Parser/heading-reference-regressions.json');
        self::assertNotFalse($json);
        /** @var list<array{id:string, source:string, html:string}> $fixtures */
        $fixtures = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures as $fixture) {
            $cases[$fixture['id']] = [$fixture['source'], $fixture['html']];
        }

        return $cases;
    }

    public function testHeadingNodesProvideLabelsForLinksAndImages(): void
    {
        $converter = new DjotConverter(warnings: true);
        $html = $converter->convert("[link][h more] ![image][h more]\n\n> # *h*\n> # `more`\n> [r]: /u\n");
        self::assertStringContainsString('<a href="#h-more">link</a>', $html);
        self::assertStringContainsString('<img alt="image" src="#h-more">', $html);
        $warnings = $converter->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame("Reference 'r' defined but never used", $warnings[0]->getMessage());
        self::assertSame(5, $warnings[0]->getLine());
    }

    public function testExplicitHeadingLabelsWithoutEmptyBrackets(): void
    {
        foreach ([new DjotConverter(), new DjotConverter(parser: new BlockParser())] as $converter) {
            $html = $converter->convert("[link][h more] ![image][h more]\n\n> # h\n> # more\n");
            self::assertStringContainsString('<a href="#h-more">link</a>', $html);
            self::assertStringContainsString('<img alt="image" src="#h-more">', $html);
        }
    }

    public function testFencedHeadingTextDoesNotDefineAReference(): void
    {
        $parser = new BlockParser(collectWarnings: true);
        $html = (new DjotConverter(parser: $parser))->convert("[fake][]\n\n```\n# fake\n```\n");
        self::assertStringContainsString('<a>fake</a>', $html);
        self::assertNull($parser->getReference('fake'));
        self::assertCount(1, $parser->getWarnings());
        self::assertSame("Undefined reference 'fake'", $parser->getWarnings()[0]->getMessage());
    }

    public function testExplicitDefinitionsOverrideContainerHeadingReferences(): void
    {
        foreach (["[h]: /u\n\n> # h\n", "> # h\n> [h]: /u\n"] as $body) {
            $converter = new DjotConverter(warnings: true);
            $html = $converter->convert("[h][] ![h][]\n\n" . $body);
            self::assertStringContainsString('<a href="/u">h</a>', $html);
            self::assertStringContainsString('src="/u"', $html);
            self::assertSame([], $converter->getWarnings());
        }
    }

    public function testReferenceAttributesAreReservedBeforeHeadingIds(): void
    {
        $converter = new DjotConverter(warnings: true);
        $html = $converter->convert("[h][] [x][r]\n\n# h\n\n{#h}\n[r]: /u\n");
        self::assertStringContainsString('<a href="#h-1">h</a>', $html);
        self::assertStringContainsString('<a href="/u" id="h">x</a>', $html);
        self::assertStringContainsString('<section id="h-1">', $html);
        self::assertSame([], $converter->getWarnings());
    }

    public function testContainerDuplicateHeadingReferencesUseTheFirstId(): void
    {
        $converter = new DjotConverter(warnings: true);
        $html = $converter->convert("[h][]\n\n> # h\n\n# h\n");
        self::assertStringContainsString('<a href="#h">h</a>', $html);
        self::assertStringContainsString('<h1 id="h">h</h1>', $html);
        self::assertStringContainsString('<section id="h-1">', $html);
        self::assertSame([], $converter->getWarnings());
    }

    private function normalize(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/>\s+</', '><', $html) ?? $html) ?? $html);
    }
}
