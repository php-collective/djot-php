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
 * at checkpoints after memory has grown by half (normally 4 to 64 MB),
 * or at the end of the outer scope when its root buffer is full.
 * Near the memory limit, checkpoints use the remaining headroom. The threshold
 * carries over between calls so garbage from earlier conversions is collected.
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
            // Kept across calls: garbage from earlier conversions counts too.
            $next = self::nextCollection();
            gc_disable();
            self::$collectAt = self::$collectAt === 0 ? $next : min(self::$collectAt, $next);
        }

        self::$depth++;
        try {
            return $work();
        } finally {
            try {
                if (self::$depth === 1) {
                    try {
                        self::finish();
                    } finally {
                        gc_enable();
                    }
                }
            } finally {
                self::$depth--;
            }
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

    private static function finish(): void
    {
        if (memory_get_usage() >= self::$collectAt) {
            self::collect();

            return;
        }
        $status = gc_status();
        if ($status['roots'] >= $status['threshold']) {
            self::collect();
        }
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

        $limit = self::memoryLimit();
        if ($limit > $usage) {
            $headroom = max(0, $limit - $usage);
            $growth = min($growth, max(256 << 10, intdiv($headroom, 2)));
        }

        return $usage + $growth;
    }

    private static function memoryLimit(): int
    {
        $raw = trim((string)ini_get('memory_limit'));
        if (preg_match('/\A(-?[0-9]+)([KMG]?)\z/i', $raw, $parts) !== 1) {
            return -1;
        }
        $amount = (int)$parts[1];
        $shift = match (strtoupper($parts[2])) {
            'K' => 10,
            'M' => 20,
            'G' => 30,
            default => 0,
        };
        if ($amount <= 0 || $amount > (PHP_INT_MAX >> $shift)) {
            return -1;
        }

        return $amount << $shift;
    }
}
