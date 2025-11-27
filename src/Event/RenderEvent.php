<?php

declare(strict_types=1);

namespace Djot\Event;

use Djot\Node\Node;

/**
 * Event dispatched during node rendering
 *
 * Allows listeners to:
 * - Modify the node before rendering
 * - Replace the rendered HTML entirely
 * - Prevent default rendering
 */
class RenderEvent
{
    protected ?string $html = null;

    protected bool $preventDefault = false;

    public function __construct(protected Node $node)
    {
    }

    /**
     * Get the node being rendered
     */
    public function getNode(): Node
    {
        return $this->node;
    }

    /**
     * Set custom HTML output, bypassing default rendering
     */
    public function setHtml(string $html): void
    {
        $this->html = $html;
        $this->preventDefault = true;
    }

    /**
     * Get the custom HTML if set
     */
    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * Check if default rendering should be skipped
     */
    public function isDefaultPrevented(): bool
    {
        return $this->preventDefault;
    }

    /**
     * Prevent default rendering without providing replacement HTML
     */
    public function preventDefault(): void
    {
        $this->preventDefault = true;
    }
}
