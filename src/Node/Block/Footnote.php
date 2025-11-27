<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Footnote definition block
 */
class Footnote extends BlockNode
{
    public function __construct(protected string $label = '')
    {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return 'footnote';
    }
}
