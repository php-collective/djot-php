<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Soft line break (newline in source)
 */
class SoftBreak extends InlineNode
{
    public function getType(): string
    {
        return 'soft_break';
    }
}
