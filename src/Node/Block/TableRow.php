<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table row
 */
class TableRow extends BlockNode
{
    public function __construct(protected bool $isHeader = false)
    {
    }

    public function isHeader(): bool
    {
        return $this->isHeader;
    }

    public function getType(): string
    {
        return 'table_row';
    }
}
