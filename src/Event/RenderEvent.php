<?php

declare(strict_types=1);

namespace Djot\Event;

use Closure;
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

    /**
     * Callback to render children (lazily invoked)
     *
     * @var \Closure(): string|null
     */
    protected ?Closure $childrenRenderer = null;

    /**
     * Cached children HTML
     */
    protected ?string $childrenHtml = null;

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

    /**
     * Set the callback for rendering children HTML
     *
     * @param \Closure(): string $renderer
     */
    public function setChildrenRenderer(Closure $renderer): void
    {
        $this->childrenRenderer = $renderer;
    }

    /**
     * Get the rendered HTML of the node's children
     *
     * This method lazily renders children on first call and caches the result.
     * Useful when you want to wrap children in a different element.
     */
    public function getChildrenHtml(): string
    {
        if ($this->childrenHtml === null) {
            $this->childrenHtml = $this->childrenRenderer !== null
                ? ($this->childrenRenderer)()
                : '';
        }

        return $this->childrenHtml;
    }
}
