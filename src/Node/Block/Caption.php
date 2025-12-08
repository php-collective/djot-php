<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Caption block for images, tables, and blockquotes.
 *
 * In Djot syntax: `^ Caption text`
 *
 * The caption applies to the immediately preceding block (image, table, or blockquote).
 */
class Caption extends BlockNode
{
    public function getType(): string
    {
        return 'caption';
    }
}
