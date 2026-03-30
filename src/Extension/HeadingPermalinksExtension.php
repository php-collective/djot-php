<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Renderer\HtmlRenderer;

/**
 * Adds permalink anchors to headings
 *
 * Appends (or prepends) a clickable anchor link to each heading, allowing users
 * to get a direct link to that section.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new HeadingPermalinksExtension());
 *
 * // Or with custom settings:
 * $converter->addExtension(new HeadingPermalinksExtension(
 *     symbol: '#',
 *     position: 'before',
 *     cssClass: 'heading-link',
 *     ariaLabel: 'Link to this section',
 * ));
 *
 * // Show on hover only, with copy-to-clipboard:
 * $converter->addExtension(new HeadingPermalinksExtension(
 *     showOnHover: true, // adds 'permalink-hover' class to wrapper
 *     copyToClipboard: true, // adds 'data-permalink-copy' attribute to link
 * ));
 * ```
 *
 * When `showOnHover` is enabled, add CSS like:
 * ```css
 * .permalink-hover { opacity: 0; transition: opacity .2s; }
 * h1:hover > .permalink-hover, h2:hover > .permalink-hover,
 * h3:hover > .permalink-hover, h4:hover > .permalink-hover,
 * h5:hover > .permalink-hover, h6:hover > .permalink-hover,
 * .permalink-hover:focus-within { opacity: 1; }
 * ```
 *
 * When `copyToClipboard` is enabled, add JavaScript like:
 * ```javascript
 * document.addEventListener('click', function(e) {
 *     var a = e.target.closest('[data-permalink-copy]');
 *     if (!a) return;
 *     e.preventDefault();
 *     navigator.clipboard.writeText(a.href);
 *     history.replaceState(null, null, a.getAttribute('href'));
 * });
 * ```
 *
 * Output (with default settings):
 * ```html
 * <section id="my-heading">
 * <h2>My Heading <a href="#my-heading" class="permalink" aria-label="Permalink">¶</a></h2>
 * </section>
 * ```
 */
class HeadingPermalinksExtension implements ExtensionInterface
{
    /**
     * @param string $symbol The symbol to display (e.g., '¶', '#', '🔗')
     * @param string $position Where to place the link: 'before' or 'after'
     * @param string $cssClass CSS class for the permalink link
     * @param string $ariaLabel Accessibility label for the link
     * @param array<int> $levels Which heading levels to add permalinks to (1-6)
     * @param bool $showOnHover Only show permalink on heading hover (injects CSS)
     * @param bool $copyToClipboard Copy URL to clipboard on click instead of navigating (injects JS)
     */
    public function __construct(
        protected string $symbol = '¶',
        protected string $position = 'after',
        protected string $cssClass = 'permalink',
        protected string $ariaLabel = 'Permalink',
        protected array $levels = [1, 2, 3, 4, 5, 6],
        protected bool $showOnHover = false,
        protected bool $copyToClipboard = false,
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Only works with HTML output - requires heading ID tracking
        if (!$converter->getRenderer() instanceof HtmlRenderer) {
            return;
        }

        $tracker = $converter->getHeadingIdTracker();

        $converter->on('render.heading', function (RenderEvent $event) use ($tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            if (!in_array($node->getLevel(), $this->levels, true)) {
                return;
            }

            // Get the deduplicated ID from the shared tracker
            $id = $tracker->getIdForHeading($node);

            if ($id === '') {
                return;
            }

            if ($this->hasPermalink($node, $id)) {
                return;
            }

            // Set it explicitly so the renderer uses this ID, not one generated
            // from the modified heading content (which would include the permalink symbol)
            if (!$node->hasAttribute('id')) {
                $node->setAttribute('id', $id);
            }

            // Create permalink span with link
            $link = new Link('#' . $id);
            $link->addClass($this->cssClass);
            $link->setAttribute('aria-label', $this->ariaLabel);
            $link->appendChild(new Text($this->symbol));

            if ($this->copyToClipboard) {
                $link->setAttribute('data-permalink-copy', '');
            }

            // Wrap in span for styling flexibility
            $span = new Span();
            $span->addClass('permalink-wrapper');
            if ($this->showOnHover) {
                $span->addClass('permalink-hover');
            }
            $span->appendChild($link);

            // Add to heading
            if ($this->position === 'before') {
                $space = new Text(' ');
                $node->prependChild($space);
                $node->prependChild($span);
            } else {
                $node->appendChild(new Text(' '));
                $node->appendChild($span);
            }
        });
    }

    protected function hasPermalink(Heading $node, string $id): bool
    {
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof Span) {
                continue;
            }

            $classes = preg_split('/\s+/', trim((string)$child->getAttribute('class')));
            if (!is_array($classes) || !in_array('permalink-wrapper', $classes, true)) {
                continue;
            }

            foreach ($child->getChildren() as $grandChild) {
                if ($grandChild instanceof Link && $grandChild->getDestination() === '#' . $id) {
                    return true;
                }
            }
        }

        return false;
    }
}
