<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReviewNeighbourRegressionTest extends TestCase
{
    #[DataProvider('regressionProvider')]
    public function testReviewNeighbours(string $source, string $html): void
    {
        foreach ([new DjotConverter(), new DjotConverter(parser: new BlockParser())] as $converter) {
            self::assertSame($html, $converter->convert($source));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function regressionProvider(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/Fixture/Parser/review-neighbour-regressions.json');
        self::assertNotFalse($json);
        /** @var list<array{id:string, source:string, html:string}> $fixtures */
        $fixtures = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures as $fixture) {
            $cases[$fixture['id']] = [$fixture['source'], $fixture['html']];
        }

        return $cases;
    }
}
