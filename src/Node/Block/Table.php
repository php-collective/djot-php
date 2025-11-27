<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table container
 */
class Table extends BlockNode
{
    public function getType(): string
    {
        return 'table';
    }
}
