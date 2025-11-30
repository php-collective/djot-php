<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * List item
 */
class ListItem extends BlockNode
{
    /**
     * @var string
     */
    public const STATE_PENDING = 'pending';

    /**
     * @var string
     */
    public const STATE_DONE = 'done';

    /**
     * @var string
     */
    public const STATE_CANCELLED = 'cancelled';

    /**
     * @var string
     */
    public const STATE_DEFERRED = 'deferred';

    /**
     * @var string
     */
    public const STATE_QUESTION = 'question';

    public function __construct(
        protected ?bool $checked = null,
        protected ?string $taskState = null,
    ) {
        // BC: derive taskState from checked if not explicitly set
        if ($this->taskState === null && $this->checked !== null) {
            $this->taskState = $this->checked ? self::STATE_DONE : self::STATE_PENDING;
        }
        // Also derive checked from taskState for BC
        if ($this->taskState !== null && $this->checked === null) {
            $this->checked = $this->taskState === self::STATE_DONE;
        }
    }

    /**
     * For task lists: null = not a task, true = checked, false = unchecked
     *
     * @deprecated Use getTaskState() for extended states
     */
    public function getChecked(): ?bool
    {
        return $this->checked;
    }

    /**
     * Get the task state for extended task lists
     *
     * @return string|null One of STATE_* constants, or null if not a task
     */
    public function getTaskState(): ?string
    {
        return $this->taskState;
    }

    public function isTask(): bool
    {
        return $this->taskState !== null;
    }

    public function getType(): string
    {
        return 'list_item';
    }
}
