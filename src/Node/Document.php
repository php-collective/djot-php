<?php

declare(strict_types=1);

namespace Djot\Node;

/**
 * Root document node
 */
class Document extends Node
{
    public function getType(): string
    {
        return 'document';
    }
}
