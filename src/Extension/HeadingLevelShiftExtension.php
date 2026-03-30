<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Node\Document;
use Djot\Node\Node;

/**
 * Shifts heading levels down (h1 → h2, h2 → h3, etc.)
 *
 * Useful when h1 is reserved for the page title and document headings
 * should start at h2 or lower for SEO and accessibility.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 *
 * // Shift by 1: h1 → h2, h2 → h3, etc.
 * $converter->addExtension(new HeadingLevelShiftExtension(shift: 1));
 *
 * // Shift by 2: h1 → h3, h2 → h4, etc.
 * $converter->addExtension(new HeadingLevelShiftExtension(shift: 2));
 * ```
 *
 * Note: Heading levels are capped at h6 (enforced by Heading::setLevel).
 * Works with all renderers (HTML, Markdown, PlainText, ANSI).
 */
class HeadingLevelShiftExtension implements ExtensionInterface
{
    /**
     * @param int $shift Number of levels to shift (1-5). Values are clamped to valid range.
     */
    public function __construct(protected int $shift = 1)
    {
        $this->shift = max(0, min($shift, 5));
    }

    public function register(DjotConverter $converter): void
    {
        // Registration not needed - we use beforeRender hook
    }

    /**
     * Modify heading levels in the AST before rendering
     */
    public function beforeRender(Document $document): void
    {
        if ($this->shift === 0) {
            return;
        }

        $this->walkAndShift($document);
    }

    /**
     * Recursively walk the AST and shift heading levels
     */
    protected function walkAndShift(Node $node): void
    {
        if ($node instanceof Heading) {
            $node->setLevel($node->getLevel() + $this->shift);
        }

        foreach ($node->getChildren() as $child) {
            $this->walkAndShift($child);
        }
    }
}
