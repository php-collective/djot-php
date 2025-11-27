<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Deleted text {-text-}
 */
class Delete extends InlineNode
{
    public function getType(): string
    {
        return 'delete';
    }
}
