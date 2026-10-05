<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Profile;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PHP's cycle collector walks the whole node tree on every run, and a large
 * document used to trigger dozens of runs that freed nothing (O(n^1.5)).
 * Run counts are asserted instead of wall-clock time, so the guard holds on a
 * busy runner and under coverage.
 */
class CycleCollectionPacingTest extends TestCase
{
    public function testALargeTableDoesNotRunTheCollectorRepeatedly(): void
    {
        $converter = new DjotConverter();
        $runs = gc_status()['runs'];

        $converter->convert(str_repeat("|x|y|\n", 30000));

        self::assertLessThanOrEqual(3, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    public function testACallerThatDisabledTheCollectorKeepsItDisabled(): void
    {
        gc_disable();
        try {
            (new DjotConverter())->convert("|x|\n\n*[HTML]: Hyper\n");

            self::assertFalse(gc_enabled());
        } finally {
            gc_enable();
        }
    }

    /**
     * A fresh process, so the collection headroom is not sized by the suite's heap.
     */
    #[RunInSeparateProcess]
    public function testRepeatedSmallConversionsStillCollect(): void
    {
        $converter = new DjotConverter();
        $converter->setProfile(Profile::full());
        gc_collect_cycles();
        $before = memory_get_usage();
        $runs = gc_status()['runs'];

        for ($i = 0; $i < 8000; $i++) {
            $converter->convert("hello *world*\n");
        }

        self::assertGreaterThan($runs, gc_status()['runs']);
        self::assertLessThan(16 << 20, memory_get_usage() - $before);
    }

    public function testNestedConversionsRestoreCollectionAfterAnException(): void
    {
        $converter = new DjotConverter();
        $converter->addOutputTransformer(static function (string $html): string {
            (new DjotConverter())->convert("nested paragraph\n");
            self::assertFalse(gc_enabled());

            throw new RuntimeException('render failed');
        });

        try {
            $converter->convert("outer paragraph\n");
            self::fail('Expected the output transformer to throw');
        } catch (RuntimeException $exception) {
            self::assertSame('render failed', $exception->getMessage());
        }
        self::assertTrue(gc_enabled());
    }

    public function testLateAbbreviationExpansionLeavesNoCycles(): void
    {
        $converter = new DjotConverter();
        $source = str_repeat("plain words here\n\n", 2000) . "*[HTML]: Hyper\n";
        gc_collect_cycles();

        gc_disable();
        try {
            $document = $converter->parse($source);
        } finally {
            gc_enable();
        }

        self::assertLessThan(100, gc_collect_cycles());
        self::assertNotEmpty($document->getChildren());
    }
}
