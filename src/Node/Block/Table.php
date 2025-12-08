<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table container
 */
class Table extends BlockNode
{
    protected ?Caption $caption = null;

    public function getType(): string
    {
        return 'table';
    }

    public function setCaption(Caption $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?Caption
    {
        return $this->caption;
    }

    public function hasCaption(): bool
    {
        return $this->caption !== null;
    }
}
