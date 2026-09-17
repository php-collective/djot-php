<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Document;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Mention;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Util\UrlSafety;
use Exception;

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
class MentionsExtension implements BeforeRenderExtensionInterface
{
    protected ?Closure $resolver;

    /**
     * @param string $urlTemplate URL template for @mentions. Use {username} placeholder.
     * @param string $cssClass CSS class for mention links
     * @param callable(\Djot\Extension\SocialLinkResolverInput): (?string)|null $resolver Authoritative mention resolver
     * @param mixed $resolverContext Opaque host context passed to the resolver
     */
    public function __construct(
        protected string $urlTemplate = '/users/view/{username}',
        protected string $cssClass = 'mention',
        ?callable $resolver = null,
        protected mixed $resolverContext = null,
    ) {
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
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
                $url = $this->resolver === null
                    ? str_replace('{username}', rawurlencode($username), $urlTemplate)
                    : '';

                $link = new Mention($username, $url);
                $link->setAttribute('data-username', $username);
                $link->appendChild(new Text('@' . $username));

                return $link;
            },
        );

        // Add render listener to apply CSS class
        $converter->on('render.link', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Mention) {
                return;
            }

            foreach (explode(' ', $this->cssClass) as $class) {
                $node->addClass($class);
            }
        });
    }

    public function beforeRender(Document $document): Document
    {
        $this->resolveMentions($document);

        return $document;
    }

    protected function resolveMentions(Node $node): void
    {
        if ($node instanceof Mention && $this->resolver !== null) {
            try {
                $destination = ($this->resolver)(new SocialLinkResolverInput(
                    $node->getUsername(),
                    $node->getAttributes(),
                    $this->resolverContext,
                ));
                $node->setDestination(is_string($destination) ? UrlSafety::sanitize($destination) : '');
            } catch (Exception) {
                $node->setDestination('');
            }
        }
        foreach ($node->getChildren() as $child) {
            $this->resolveMentions($child);
        }
    }
}
