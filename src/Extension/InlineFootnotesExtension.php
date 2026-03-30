<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Span;
use Djot\Renderer\HtmlRenderer;

/**
 * Converts spans with `.fn` class to inline footnotes
 *
 * Inline footnotes allow footnote content to be written inline with the text,
 * rather than requiring a separate footnote definition block.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new InlineFootnotesExtension());
 *
 * $html = $converter->convert('Some text[An inline footnote]{.fn} continues.');
 * ```
 *
 * The footnote content supports full inline formatting:
 * ```djot
 * Text[A footnote with _emphasis_ and `code`]{.fn} here.
 * ```
 *
 * Inline footnotes integrate seamlessly with regular footnotes - they share
 * the same numbering sequence and appear together in the footnotes section.
 *
 * Note: Additional attributes on the span (other classes, IDs, etc.) are not
 * preserved on the generated footnote reference, consistent with regular
 * footnote syntax which also doesn't support attributes.
 *
 * For non-HTML renderers, use InlineFootnotesToParenthesesTransform to convert
 * inline footnotes into explicit parenthetical inline content before rendering.
 *
 * @see https://github.com/jgm/djot/issues/286
 */
class InlineFootnotesExtension implements ExtensionInterface
{
    /**
     * @param string $cssClass CSS class that marks a span as an inline footnote
     */
    public function __construct(protected string $cssClass = 'fn')
    {
    }

    public function register(DjotConverter $converter): void
    {
        $renderer = $converter->getRenderer();

        // HTML renderer uses events for proper footnote handling. Non-HTML
        // renderers should use an explicit transform instead of mutating the
        // caller-owned AST during render().
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $cssClass = $this->cssClass;

        $converter->on('render.span', function (RenderEvent $event) use ($renderer, $cssClass): void {
            $node = $event->getNode();
            if (!$node instanceof Span) {
                return;
            }

            // Check if this span has the footnote class
            if (!$node->hasClass($cssClass)) {
                return;
            }

            // Register with the renderer and get the footnote number.
            // Content rendering is deferred to ensure this inline footnote's number
            // is reserved before any nested footnotes in its content are rendered.
            $number = $renderer->registerInlineFootnote(function () use ($event): string {
                $content = $event->getChildrenHtml();

                // Normalize content to a paragraph to ensure consistent rendering
                // with regular footnotes and reliable backlink insertion.
                $trimmedContent = trim($content);
                if (!(str_starts_with($trimmedContent, '<p>') && str_ends_with($trimmedContent, '</p>'))) {
                    return '<p>' . $trimmedContent . '</p>';
                }

                return $trimmedContent;
            });

            // Output the footnote reference in the same structure as regular footnotes
            $html = '<a id="fnref' . $number . '" href="#fn' . $number . '" role="doc-noteref"';
            if ($htmlRenderer->isRoundTripMode()) {
                $contentHtml = trim($event->getChildrenHtml());
                $html .= ' data-djot-inline-footnote-html="'
                    . htmlspecialchars($contentHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                if ($cssClass !== 'fn') {
                    $html .= ' data-djot-inline-footnote-class="'
                        . htmlspecialchars($cssClass, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                }
            }
            $html .= '><sup>' . $number . '</sup>';
            $html .= '</a>';

            $event->setHtml($html);
        });
    }

    /**
     * Check if a span is a footnote span
     */
    protected function isFootnoteSpan(Span $span): bool
    {
        return $span->hasClass($this->cssClass);
    }
}
