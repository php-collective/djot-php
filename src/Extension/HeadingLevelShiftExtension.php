<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;

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
 * Note: Heading levels are capped at h6. When this extension shifts a heading,
 * it outputs the heading directly without automatic `<section>` wrapping.
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

        $tracker = $converter->getHeadingIdTracker();
        $renderer = $converter->getRenderer();
        $shift = $this->shift;

        $converter->on('render.heading', function (RenderEvent $event) use ($tracker, $renderer, $shift): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            $originalLevel = $node->getLevel();
            $newLevel = min($originalLevel + $shift, 6);

            if ($newLevel === $originalLevel) {
                return;
            }

            $id = $tracker->getIdForHeading($node);
            $attrs = $renderer->renderAttributesExcluding($node, ['id']);

            $html = '<h' . $newLevel . ' id="' . $renderer->escapeAttribute($id) . '"' . $attrs . '>'
                . $event->getChildrenHtml()
                . '</h' . $newLevel . ">\n";

            $event->setHtml($html);
        });
    }
}
