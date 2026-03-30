<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

/**
 * Parses @mentions into user profile links
 *
 * Converts @username patterns into clickable links.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new MentionsExtension());
 *
 * $html = $converter->convert('Hello @johndoe!');
 * // Output: <p>Hello <a href="/users/view/johndoe" class="mention">@johndoe</a>!</p>
 * ```
 *
 * Custom URL template:
 * ```php
 * $converter->addExtension(new MentionsExtension(
 *     urlTemplate: '/profile/{username}',
 * ));
 * ```
 */
class MentionsExtension implements ExtensionInterface
{
    /**
     * @param string $urlTemplate URL template for @mentions. Use {username} placeholder.
     * @param string $cssClass CSS class for mention links
     */
    public function __construct(
        protected string $urlTemplate = '/users/view/{username}',
        protected string $cssClass = 'mention',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();
        $urlTemplate = $this->urlTemplate;

        // Register @username pattern
        $inlineParser->addInlinePattern(
            '/@([a-zA-Z0-9_-]+)/',
            function (string $match, array $groups) use ($urlTemplate): Link {
                $username = $groups[1];
                $url = str_replace('{username}', rawurlencode($username), $urlTemplate);

                $link = new Link($url);
                $link->setAttribute('data-username', $username);
                $link->appendChild(new Text('@' . $username));

                return $link;
            },
        );

        // Add render listener to apply CSS class
        $converter->on('render.link', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Link) {
                return;
            }

            $username = $node->getAttribute('data-username');
            if ($username !== null) {
                foreach (explode(' ', $this->cssClass) as $class) {
                    $node->addClass($class);
                }
            }
        });
    }
}
