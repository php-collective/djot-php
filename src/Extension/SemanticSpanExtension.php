<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Span;

/**
 * Converts spans with semantic attributes to proper HTML elements
 *
 * Transforms span attributes into semantic HTML5 elements:
 * - `kbd` attribute → `<kbd>` (keyboard input)
 * - `dfn` attribute → `<dfn>` (definition/term)
 * - `abbr` attribute → `<abbr>` (abbreviation, with title)
 *
 * These can also be combined, with `dfn` wrapping inner elements.
 *
 * Example usage:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new SemanticSpanExtension());
 *
 * // Keyboard shortcuts
 * echo $converter->convert('[Ctrl+C]{kbd}');
 * // Output: <p><kbd>Ctrl+C</kbd></p>
 *
 * // Definitions
 * echo $converter->convert('[API]{dfn="Application Programming Interface"}');
 * // Output: <p><dfn title="Application Programming Interface">API</dfn></p>
 *
 * // Abbreviations
 * echo $converter->convert('[HTML]{abbr="HyperText Markup Language"}');
 * // Output: <p><abbr title="HyperText Markup Language">HTML</abbr></p>
 *
 * // Combined (definition of an abbreviation)
 * echo $converter->convert('[CSS]{dfn abbr="Cascading Style Sheets"}');
 * // Output: <p><dfn><abbr title="Cascading Style Sheets">CSS</abbr></dfn></p>
 * ```
 *
 * Djot syntax:
 * - `[text]{kbd}` or `[text]{kbd=""}` - keyboard input
 * - `[text]{dfn}` or `[text]{dfn="title"}` - definition (optional title)
 * - `[text]{abbr="title"}` - abbreviation (title required for meaning)
 *
 * Note: This extension provides manual control over semantic elements via attributes.
 * For automatic abbreviation expansion (defining once, applying everywhere), use the
 * built-in abbreviation definition syntax instead:
 * ```
 * *[HTML]: HyperText Markup Language
 * The HTML specification defines...
 * ```
 */
class SemanticSpanExtension implements ExtensionInterface
{
    public function register(DjotConverter $converter): void
    {
        $converter->on('render.span', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Span) {
                return;
            }

            $kbd = $node->getAttribute('kbd');
            $dfn = $node->getAttribute('dfn');
            $abbr = $node->getAttribute('abbr');

            // Check if any semantic attribute is present
            if ($kbd === null && $dfn === null && $abbr === null) {
                return;
            }

            // Remove semantic attributes from node (they're processed, not rendered)
            $node->removeAttribute('kbd');
            $node->removeAttribute('dfn');
            $node->removeAttribute('abbr');

            // Get remaining attributes for outer wrapper
            $remainingAttrs = $node->getAttributes();

            // Get children HTML
            $content = $event->getChildrenHtml();

            // Build inner content with semantic elements
            // Priority: abbr innermost, kbd, dfn outermost
            $html = $content;

            // Wrap in <abbr> if abbr attribute present (with title)
            if ($abbr !== null && $abbr !== '') {
                $html = '<abbr title="' . $this->escapeAttribute((string)$abbr) . '">' . $html . '</abbr>';
            }

            // Wrap in <kbd> if kbd attribute present
            if ($kbd !== null) {
                $html = '<kbd>' . $html . '</kbd>';
            }

            // Wrap in <dfn> if dfn attribute present (with optional title)
            if ($dfn !== null) {
                if ($dfn !== '') {
                    $html = '<dfn title="' . $this->escapeAttribute((string)$dfn) . '">' . $html . '</dfn>';
                } else {
                    $html = '<dfn>' . $html . '</dfn>';
                }
            }

            // Wrap in span with remaining attributes if any exist
            if ($remainingAttrs !== []) {
                $attrsHtml = $this->renderAttributes($remainingAttrs);
                $html = '<span' . $attrsHtml . '>' . $html . '</span>';
            }

            $event->setHtml($html);
        });
    }

    /**
     * Escape a string for use in an HTML attribute
     */
    protected function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render attributes as HTML attribute string
     *
     * @param array<string, string> $attrs
     */
    protected function renderAttributes(array $attrs): string
    {
        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . htmlspecialchars($key, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '="' . $this->escapeAttribute((string)$value) . '"';
        }

        return $html;
    }
}
