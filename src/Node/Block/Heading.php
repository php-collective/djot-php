<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Heading block (h1-h6)
 */
class Heading extends BlockNode
{
    public function __construct(protected int $level = 1)
    {
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getType(): string
    {
        return 'heading';
    }
}
