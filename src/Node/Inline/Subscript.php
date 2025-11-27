<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Subscript text~sub~
 */
class Subscript extends InlineNode
{
    public function getType(): string
    {
        return 'subscript';
    }
}
