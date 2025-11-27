<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Highlighted text {=text=}
 */
class Highlight extends InlineNode
{
    public function getType(): string
    {
        return 'highlight';
    }
}
