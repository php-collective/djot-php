<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Document;
use Djot\Renderer\RendererInterface;
use Djot\Transform\HeadingLevelShiftTransform;

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
class HeadingLevelShiftExtension implements BeforeRenderExtensionInterface
{
    protected ?RendererInterface $renderer = null;

    /**
     * @param int $shift Number of levels to shift (1-5). Values are clamped to valid range.
     */
    public function __construct(protected int $shift = 1)
    {
        $this->shift = max(0, min($shift, 5));
    }

    public function register(DjotConverter $converter): void
    {
        $this->renderer = $converter->getRenderer();
    }

    /**
     * Return a shifted copy of the document for rendering.
     */
    public function beforeRender(Document $document): Document
    {
        if ($this->shift === 0) {
            return $document;
        }

        $transform = new HeadingLevelShiftTransform($this->shift);
        if ($this->renderer !== null) {
            return $transform->transformForRenderer($document, $this->renderer);
        }

        return $transform->transform($document);
    }
}
