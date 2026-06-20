<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\Node\Block\ListItem;

/**
 * A flat, mutable list of placed table cells used while the
 * {@see \Djot\Extension\ListTableExtension} resolves `^` / `<` span markers.
 *
 * Each descriptor records the origin cell (a list item), the effective column
 * it starts at, and its resolved rowspan / colspan. Keeping them in one typed
 * list - referenced from the grid by integer index - lets span markers grow an
 * earlier cell's span without losing the descriptor's array shape.
 */
class SpanDescriptors
{
    /**
     * @var array<int, array{cell: \Djot\Node\Block\ListItem, col: int, rowspan: int, colspan: int}>
     */
    protected array $descriptors = [];

    /**
     * Add an origin cell at the given effective column and return its index.
     */
    public function add(ListItem $cell, int $col): int
    {
        $index = count($this->descriptors);
        $this->descriptors[$index] = [
            'cell' => $cell,
            'col' => $col,
            'rowspan' => 1,
            'colspan' => 1,
        ];

        return $index;
    }

    /**
     * Grow the colspan of the descriptor at the given index by one.
     */
    public function growColspan(int $index): void
    {
        $this->descriptors[$index]['colspan']++;
    }

    /**
     * Grow the rowspan of the descriptor at the given index by one.
     */
    public function growRowspan(int $index): void
    {
        $this->descriptors[$index]['rowspan']++;
    }

    /**
     * Get the descriptor at the given index.
     *
     * @return array{cell: \Djot\Node\Block\ListItem, col: int, rowspan: int, colspan: int}
     */
    public function get(int $index): array
    {
        return $this->descriptors[$index];
    }
}
