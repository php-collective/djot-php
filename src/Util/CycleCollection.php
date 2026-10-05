<?php

declare(strict_types=1);

namespace Djot\Util;

use Closure;

/**
 * Paces PHP's cycle collector during a parse or render.
 *
 * The collector runs whenever its root buffer fills and walks everything those
 * roots reach, which in a tree with parent links is the whole tree. Its
 * threshold grows by a fixed step, so a large document paid O(n^1.5) in
 * collections that freed nothing. Inside a paused section a collection runs
 * only at a checkpoint after memory has grown by half (at least 4 MB, at most
 * 64 MB), and the threshold carries over between calls so garbage from earlier
 * conversions is still collected.
 *
 * @internal
 */
final class CycleCollection
{
    private const GROWTH = 0.5;

    /**
     * @var int
     */
    private const MIN_GROWTH_BYTES = 4 << 20;

    /**
     * Caps the garbage a long-lived process can hold between collections.
     *
     * @var int
     */
    private const MAX_GROWTH_BYTES = 64 << 20;

    private static int $depth = 0;

    private static int $collectAt = 0;

    /**
     * Run $work with automatic collection paused, unless the caller disabled it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function paused(Closure $work): mixed
    {
        if (self::$depth === 0) {
            if (!gc_enabled()) {
                return $work();
            }
            gc_disable();
            // Kept across calls: garbage from earlier conversions counts too.
            if (self::$collectAt === 0) {
                self::$collectAt = self::nextCollection();
            }
        }

        self::$depth++;
        try {
            return $work();
        } finally {
            if (self::$depth === 1) {
                self::checkpoint();
                gc_enable();
            }
            self::$depth--;
        }
    }

    /**
     * Collect if memory has grown enough since the last collection.
     */
    public static function checkpoint(): void
    {
        if (self::$depth === 0 || memory_get_usage() < self::$collectAt) {
            return;
        }

        self::collect();
    }

    /**
     * Collect now, for a caller that has just dropped a whole tree.
     */
    public static function collect(): void
    {
        if (self::$depth === 0) {
            return;
        }

        gc_collect_cycles();
        self::$collectAt = self::nextCollection();
    }

    private static function nextCollection(): int
    {
        $usage = memory_get_usage();

        $growth = min(max((int)($usage * self::GROWTH), self::MIN_GROWTH_BYTES), self::MAX_GROWTH_BYTES);

        return $usage + $growth;
    }
}
