<?php

declare(strict_types=1);

namespace Djot\Renderer;

/**
 * Per-render mutable state for HtmlRenderer.
 */
class RenderContext
{
    /**
     * Tracks footnote reference counts for generating unique IDs.
     *
     * @var array<string, int>
     */
    public array $footnoteRefCounts = [];

    public HeadingIdTracker $headingIdTracker;

    /**
     * Maps footnote labels to their assigned numbers (order of first reference).
     *
     * @var array<string, int>
     */
    public array $footnoteNumbers = [];

    /**
     * Counter for footnote numbering.
     */
    public int $footnoteCounter = 0;

    /**
     * Collected footnote nodes for rendering at end.
     *
     * @var array<string, \Djot\Node\Block\Footnote>
     */
    public array $collectedFootnotes = [];

    /**
     * Deferred content renderers for inline footnotes (number => callback).
     *
     * @var array<int, \Closure(): string>
     */
    public array $inlineFootnoteRenderers = [];

    public function __construct(?HeadingIdTracker $headingIdTracker = null)
    {
        $this->headingIdTracker = $headingIdTracker ?? new HeadingIdTracker();
    }

    public function reset(): void
    {
        $this->footnoteRefCounts = [];
        $this->headingIdTracker->reset();
        $this->footnoteNumbers = [];
        $this->footnoteCounter = 0;
        $this->collectedFootnotes = [];
        $this->inlineFootnoteRenderers = [];
    }
}
