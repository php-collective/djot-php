<?php

declare(strict_types=1);

namespace Djot\Parser;

use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;

/**
 * Preserve the legacy unit-column placement rules without rebuilding history.
 *
 * @internal
 */
final class UnitTableSpanIndex
{
    /**
     * @var array<int, list<\Djot\Node\Block\TableCell>>
     */
    private array $rows = [];

    /**
     * @var array<int, int>
     */
    private array $occupancy = [];

    /**
     * @var array<int, list<int>>
     */
    private array $columns = [];

    /**
     * @var array<int, array{cell: \Djot\Node\Block\TableCell, row: int, slot: int}>
     */
    private array $origins = [];

    /**
     * @var array<int, int>
     */
    private array $tokens = [];

    /**
     * @var array<int, int>
     */
    private array $free = [];

    private int $capacity = 0;

    public function addRow(TableRow $row, int $rowIndex): void
    {
        $cells = [];
        $column = 0;
        foreach ($row->getChildren() as $cell) {
            if (!$cell instanceof TableCell) {
                continue;
            }
            while (isset($this->occupancy[$column])) {
                $owner = $this->occupancy[$column];
                // The legacy occupancy scan reads physical child slots here.
                $above = $this->rows[$owner][$column] ?? null;
                if ($owner + ($above?->getRowspan() ?? 1) <= $rowIndex) {
                    break;
                }
                $column++;
            }
            $slot = count($cells);
            $cells[] = $cell;
            $token = count($this->origins);
            $this->origins[$token] = ['cell' => $cell, 'row' => $rowIndex, 'slot' => $slot];
            $this->tokens[spl_object_id($cell)] = $token;
            $this->occupancy[$column] = $rowIndex;
            $this->columns[$column][] = $token;
            $column++;
        }
        $this->rows[$rowIndex] = $cells;
    }

    public function replaceRow(TableRow $row, int $rowIndex): void
    {
        $cells = [];
        foreach ($row->getChildren() as $cell) {
            if ($cell instanceof TableCell) {
                $cells[] = $cell;
            }
        }
        foreach ($this->rows[$rowIndex] as $slot => $oldCell) {
            $token = $this->tokens[spl_object_id($oldCell)];
            unset($this->tokens[spl_object_id($oldCell)]);
            $this->origins[$token]['cell'] = $cells[$slot];
            $this->tokens[spl_object_id($cells[$slot])] = $token;
        }
        $this->rows[$rowIndex] = $cells;
    }

    public function findOrigin(int $column, int $rowIndex): ?TableCell
    {
        while (($this->columns[$column] ?? []) !== []) {
            $token = $this->columns[$column][count($this->columns[$column]) - 1];
            $origin = $this->origins[$token];
            if ($origin['row'] + $origin['cell']->getRowspan() >= $rowIndex) {
                return $origin['cell'];
            }
            array_pop($this->columns[$column]);
        }

        return null;
    }

    /**
     * @param list<\Djot\Node\Block\TableCell> $extended
     * @param int $width
     *
     * @return array<int, true>
     */
    public function overlappingColumns(array $extended, int $width): array
    {
        if ($extended === []) {
            return [];
        }
        $needed = 2 * max($width, count($extended));
        $origins = [];
        foreach ($extended as $cell) {
            $origin = $this->origins[$this->tokens[spl_object_id($cell)]];
            $origins[] = $origin;
            $needed = max($needed, 2 * ($origin['slot'] + 1));
        }
        if ($needed > $this->capacity) {
            $this->capacity = max($needed, 2 * $this->capacity);
            $this->free = [0];
            for ($i = 1; $i <= $this->capacity; $i++) {
                $this->free[$i] = $i & -$i;
            }
        }
        usort($origins, static fn (array $a, array $b): int => $a['row'] <=> $b['row'] ?: $a['slot'] <=> $b['slot']);
        $occupied = [];
        $group = [];
        $sourceRow = null;
        foreach ($origins as $origin) {
            if ($sourceRow !== $origin['row']) {
                foreach ($group as $column) {
                    $occupied[$column] = true;
                    $this->update($column, -1);
                }
                $group = [];
                $sourceRow = $origin['row'];
            }
            $group[] = $this->availableColumn($origin['slot'] + 1);
        }
        foreach ($group as $column) {
            $occupied[$column] = true;
            $this->update($column, -1);
        }
        foreach ($occupied as $column => $_) {
            $this->update($column, 1);
        }

        return $occupied;
    }

    private function update(int $column, int $delta): void
    {
        for ($i = $column + 1; $i <= $this->capacity; $i += $i & -$i) {
            $this->free[$i] += $delta;
        }
    }

    private function availableColumn(int $rank): int
    {
        $at = 0;
        $step = 1;
        while ($step <= intdiv($this->capacity, 2)) {
            $step *= 2;
        }
        for (; $step > 0; $step = intdiv($step, 2)) {
            $next = $at + $step;
            if ($next <= $this->capacity && $this->free[$next] < $rank) {
                $rank -= $this->free[$next];
                $at = $next;
            }
        }

        return $at;
    }
}
