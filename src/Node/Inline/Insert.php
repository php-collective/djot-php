<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Inserted text {+text+}
 */
class Insert extends InlineNode
{
    public function getType(): string
    {
        return 'insert';
    }
}
