<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Figure block that wraps content with an optional caption.
 *
 * Used to wrap:
 * - Images with captions → <figure><img>...<figcaption>...</figcaption></figure>
 * - Blockquotes with attribution → <figure><blockquote>...<figcaption>...</figcaption></figure>
 *
 * Tables with captions use the <caption> element inside the table instead.
 */
class Figure extends BlockNode
{
    public function getType(): string
    {
        return 'figure';
    }
}
