<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;

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
     */
    public function __construct(
        protected string $symbol = '¶',
        protected string $position = 'after',
        protected string $cssClass = 'permalink',
        protected string $ariaLabel = 'Permalink',
        protected array $levels = [1, 2, 3, 4, 5, 6],
    ) {
    }

    public function register(DjotConverter $converter): void
    {
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

            // Wrap in span for styling flexibility
            $span = new Span();
            $span->addClass('permalink-wrapper');
            $span->appendChild($link);

            // Add to heading
            if ($this->position === 'before') {
                // Prepend space + span, then the space
                $space = new Text(' ');
                $node->prependChild($space);
                $node->prependChild($span);
            } else {
                // Add space before
                $node->appendChild(new Text(' '));
                $node->appendChild($span);
            }
        });
    }
}
