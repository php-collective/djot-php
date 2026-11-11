<?php

declare(strict_types=1);

namespace Djot\Parser\Utility;

/**
 * Backtick runs indexed by their width.
 *
 * @internal
 */
final class BacktickRunIndex
{
    /**
     * @var array<int, array<int>>
     */
    private array $startsByWidth = [];

    public function __construct(string $text)
    {
        $offset = 0;
        while (($start = strpos($text, '`', $offset)) !== false) {
            $end = $start + strspn($text, '`', $start);
            $this->startsByWidth[$end - $start][] = $start;
            $offset = $end;
        }
    }

    /**
     * End of the first run at or after $offset that is exactly $width wide.
     */
    public function findExactCloser(int $offset, int $width): ?int
    {
        $starts = $this->startsByWidth[$width] ?? [];
        $low = 0;
        $high = count($starts);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($starts[$middle] < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low === count($starts) ? null : $starts[$low] + $width;
    }
}
