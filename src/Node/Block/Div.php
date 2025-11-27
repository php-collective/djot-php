<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    public function getType(): string
    {
        return 'div';
    }
}
