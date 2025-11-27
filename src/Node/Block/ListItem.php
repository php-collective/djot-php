<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * List item
 */
class ListItem extends BlockNode
{
    public function __construct(protected ?bool $checked = null)
    {
    }

    /**
     * For task lists: null = not a task, true = checked, false = unchecked
     */
    public function getChecked(): ?bool
    {
        return $this->checked;
    }

    public function isTask(): bool
    {
        return $this->checked !== null;
    }

    public function getType(): string
    {
        return 'list_item';
    }
}
