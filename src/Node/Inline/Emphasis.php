<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Emphasis (underscore delimited: _text_)
 */
class Emphasis extends InlineNode
{
    public function getType(): string
    {
        return 'emphasis';
    }
}
