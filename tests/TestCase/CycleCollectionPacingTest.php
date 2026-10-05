<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Parser\BlockParser;
use Djot\Profile;
use Djot\Util\CycleCollection;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * PHP's cycle collector walks the whole node tree on every run, and a large
 * document used to trigger dozens of runs that freed nothing (O(n^1.5)).
 * Run counts are asserted instead of wall-clock time, so the guard holds on a
 * busy runner and under coverage.
 */
class CycleCollectionPacingTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testALargeTableDoesNotRunTheCollectorRepeatedly(): void
    {
        ini_set('memory_limit', '-1');
        $converter = new DjotConverter();
        $runs = gc_status()['runs'];

        $converter->convert(str_repeat("|x|y|\n", 30000));

        self::assertLessThanOrEqual(8, gc_status()['runs'] - $runs);
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

    #[RunInSeparateProcess]
    public function testDirectParserUsePacesCollection(): void
    {
        ini_set('memory_limit', '-1');
        $runs = gc_status()['runs'];
        $document = (new BlockParser())->parse(str_repeat("|x|y|\n", 30000));

        self::assertNotEmpty($document->getChildren());
        self::assertLessThanOrEqual(8, gc_status()['runs'] - $runs);
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testThrowingDestructorRestoresCollectionScope(): void
    {
        ini_set('memory_limit', '-1');
        try {
            CycleCollection::paused(static function (): void {
                $garbage = new class {
                    public ?self $cycle = null;

                    public string $payload = '';

                    public function __destruct()
                    {
                        throw new RuntimeException('destructor failed');
                    }
                };
                $garbage->cycle = $garbage;
                $garbage->payload = str_repeat('x', max(8 << 20, memory_get_usage()));
            });
            self::fail('Expected collection to propagate the destructor exception');
        } catch (RuntimeException $exception) {
            self::assertSame('destructor failed', $exception->getMessage());
        }
        self::assertTrue(gc_enabled());
        CycleCollection::paused(static function (): void {
            self::assertFalse(gc_enabled());
        });
        self::assertTrue(gc_enabled());
    }

    #[RunInSeparateProcess]
    public function testHeapShrinkLowersRetainedGarbage(): void
    {
        ini_set('memory_limit', '-1');
        $ballast = str_repeat('x', max(64 << 20, memory_get_usage() * 2));
        CycleCollection::paused(static function (): void {
        });
        unset($ballast);
        $before = memory_get_usage();
        $payloadBytes = max(64 << 10, intdiv($before, 100));
        for ($i = 0; $i < 200; $i++) {
            CycleCollection::paused(static function () use ($payloadBytes): void {
                $garbage = new stdClass();
                $garbage->cycle = $garbage;
                $garbage->payload = str_repeat('x', $payloadBytes);
            });
        }
        self::assertLessThan(max(8 << 20, $before), memory_get_usage() - $before);
    }

    #[RunInSeparateProcess]
    public function testCollectionFitsTheConfiguredMemoryLimit(): void
    {
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string)(memory_get_usage(true) + (128 << 20)));
        try {
            $ballast = str_repeat('x', 88 << 20);
            $runs = gc_status()['runs'];
            for ($i = 0; $i < 800; $i++) {
                CycleCollection::paused(static function (): void {
                    $garbage = new stdClass();
                    $garbage->cycle = $garbage;
                    $garbage->payload = str_repeat('x', 64 << 10);
                });
            }
            self::assertSame(88 << 20, strlen($ballast));
            self::assertGreaterThan($runs, gc_status()['runs']);
        } finally {
            ini_set('memory_limit', $oldLimit === false ? '-1' : $oldLimit);
        }
    }
}
