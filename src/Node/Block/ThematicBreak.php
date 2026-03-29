<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Thematic break (horizontal rule)
 */
class ThematicBreak extends BlockNode
{
    public function __construct(public readonly string $char = '-')
    {
    }

    public function getType(): string
    {
        return 'thematic_break';
    }
}
