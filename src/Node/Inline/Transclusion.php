<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Transclusion {{name|args}}
 */
class Transclusion extends InlineNode
{
    public function __construct(protected string $name = '')
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return 'transclusion';
    }
}
