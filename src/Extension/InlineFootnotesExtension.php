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
 * Text[A footnote with *emphasis* and `code`]{.fn} here.
 * ```
 *
 * Inline footnotes integrate seamlessly with regular footnotes - they share
 * the same numbering sequence and appear together in the footnotes section.
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

            // Get the rendered content of the span's children
            $content = $event->getChildrenHtml();

            // Wrap content in a paragraph if it's not already block-level
            // This ensures consistent rendering with regular footnotes
            if (!str_starts_with(trim($content), '<')) {
                $content = '<p>' . $content . '</p>';
            }

            // Register with the renderer and get the footnote number
            $number = $renderer->registerInlineFootnote($content);

            // Output the footnote reference superscript
            $html = '<sup class="footnote-ref">';
            $html .= '<a href="#fn' . $number . '" id="fnref' . $number . '" role="doc-noteref">';
            $html .= $number;
            $html .= '</a></sup>';

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
