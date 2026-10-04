<?php

declare(strict_types=1);

namespace Djot\Parser\Utility;

/**
 * Backtick runs indexed by their position and maximum width.
 *
 * @internal
 */
final class BacktickRunIndex
{
    /**
     * @var list<int>
     */
    private array $starts = [];

    /**
     * @var list<int>
     */
    private array $ends = [];

    /**
     * @var array<int, int>
     */
    private array $maxWidths = [];

    private int $leafCount = 1;

    public function __construct(string $text)
    {
        $offset = 0;
        while (($start = strpos($text, '`', $offset)) !== false) {
            $end = $start + strspn($text, '`', $start);
            $this->starts[] = $start;
            $this->ends[] = $end;
            $offset = $end;
        }
        $runCount = count($this->starts);
        while ($this->leafCount < $runCount) {
            $this->leafCount *= 2;
        }
        $this->maxWidths = array_fill(0, $this->leafCount * 2, 0);
        foreach ($this->starts as $index => $start) {
            $this->maxWidths[$this->leafCount + $index] = $this->ends[$index] - $start;
        }
        for ($node = $this->leafCount - 1; $node > 0; $node--) {
            $this->maxWidths[$node] = max($this->maxWidths[$node * 2], $this->maxWidths[$node * 2 + 1]);
        }
    }

    public function findCloser(int $offset, int $minimumWidth): ?int
    {
        $low = 0;
        $high = count($this->starts);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->starts[$middle] < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        if ($low === count($this->starts)) {
            return null;
        }
        $index = $this->findFirst(1, 0, $this->leafCount, $low, $minimumWidth);

        return $index === null ? null : $this->ends[$index];
    }

    private function findFirst(int $node, int $left, int $right, int $from, int $minimumWidth): ?int
    {
        if ($right <= $from || $this->maxWidths[$node] < $minimumWidth) {
            return null;
        }
        if ($right - $left === 1) {
            return $left;
        }
        $middle = intdiv($left + $right, 2);

        return $this->findFirst($node * 2, $left, $middle, $from, $minimumWidth)
            ?? $this->findFirst($node * 2 + 1, $middle, $right, $from, $minimumWidth);
    }
}
