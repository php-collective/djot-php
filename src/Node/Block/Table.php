<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table container
 */
class Table extends BlockNode
{
    protected ?string $caption = null;

    public function getType(): string
    {
        return 'table';
    }

    public function setCaption(string $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }
}
