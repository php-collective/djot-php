<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Span;

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
        $cssClass = $this->cssClass;

        $converter->on('render.span', function (RenderEvent $event) use ($renderer, $cssClass): void {
            $node = $event->getNode();
            if (!$node instanceof Span) {
                return;
            }

            // Check if this span has the footnote class
            $class = $node->getAttribute('class');
            if ($class === null || !is_string($class) || !$this->hasClass($class, $cssClass)) {
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
            $html = '<a id="fnref' . $number . '" href="#fn' . $number . '" role="doc-noteref">';
            $html .= '<sup>' . $number . '</sup>';
            $html .= '</a>';

            $event->setHtml($html);
        });
    }

    /**
     * Check if a class string contains the target class
     */
    protected function hasClass(string $classString, string $targetClass): bool
    {
        $classes = preg_split('/\s+/', $classString);
        if ($classes === false) {
            return false;
        }

        return in_array($targetClass, $classes, true);
    }
}
