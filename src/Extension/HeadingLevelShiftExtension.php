<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;

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
 * Note: Rendered heading levels are capped at h6.
 * Works with all built-in renderers (HTML, Markdown, PlainText, ANSI).
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
        if ($this->shift === 0) {
            return;
        }

        $renderer = $converter->getRenderer();

        if (
            $renderer instanceof HtmlRenderer
            || $renderer instanceof MarkdownRenderer
            || $renderer instanceof PlainTextRenderer
            || $renderer instanceof AnsiRenderer
        ) {
            $renderer->setHeadingLevelShift($this->shift);
        }
    }
}
