<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Symbol :name:
 */
class Symbol extends InlineNode
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
        return 'symbol';
    }
}
