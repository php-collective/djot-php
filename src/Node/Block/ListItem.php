<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * List item
 *
 * For task lists, stores the raw marker character from inside brackets:
 * - ' ' (space) = unchecked
 * - 'x' or 'X' = checked/completed
 * - Extended markers (for custom rendering via events):
 *   - '-' = cancelled/not applicable
 *   - '/' = in progress (partial)
 *   - '>' = deferred/forwarded
 *   - '?' = question/needs clarification
 *   - '*' = in process/active
 *   - '=' = paused
 *   - '.' = stopped
 *   - etc.
 */
class ListItem extends BlockNode
{
    /**
     * @param string|null $taskMarker Raw character from inside brackets, null if not a task
     */
    public function __construct(protected ?string $taskMarker = null)
    {
    }

    /**
     * Get the raw task marker character
     *
     * Returns the character inside the brackets: ' ', 'x', 'X', '-', '/', '>', '?', etc.
     * Returns null if this is not a task list item.
     */
    public function getTaskMarker(): ?string
    {
        return $this->taskMarker;
    }

    /**
     * For task lists: null = not a task, true = checked, false = unchecked
     *
     * Note: This method only recognizes standard markers (' ', 'x', 'X').
     * For extended markers, use getTaskMarker() and handle in render events.
     */
    public function getChecked(): ?bool
    {
        if ($this->taskMarker === null) {
            return null;
        }

        // Standard markers
        if ($this->taskMarker === ' ') {
            return false;
        }
        if (strtolower($this->taskMarker) === 'x') {
            return true;
        }

        // Extended markers default to unchecked for backward compatibility
        return false;
    }

    /**
     * Check if this is a task list item
     */
    public function isTask(): bool
    {
        return $this->taskMarker !== null;
    }

    /**
     * Check if task is completed (marker is 'x' or 'X')
     */
    public function isCompleted(): bool
    {
        return $this->taskMarker !== null && strtolower($this->taskMarker) === 'x';
    }

    public function getType(): string
    {
        return 'list_item';
    }
}
