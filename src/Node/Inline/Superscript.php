<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Superscript text^super^
 */
class Superscript extends InlineNode
{
    public function getType(): string
    {
        return 'superscript';
    }
}
