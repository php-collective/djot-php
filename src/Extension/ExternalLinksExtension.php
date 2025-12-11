<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;

/**
 * Adds target and rel attributes to external links
 *
 * External links (URLs starting with http:// or https://) will have:
 * - target="_blank" (opens in new tab)
 * - rel="noopener noreferrer" (security best practice)
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new ExternalLinksExtension());
 *
 * // Or with custom settings:
 * $converter->addExtension(new ExternalLinksExtension(
 *     internalHosts: ['example.com', 'www.example.com'],
 *     target: '_blank',
 *     rel: 'noopener',
 * ));
 * ```
 */
class ExternalLinksExtension implements ExtensionInterface
{
    /**
     * @param array<string> $internalHosts Hosts to treat as internal (won't get external link attributes)
     * @param string $target Target attribute value (e.g., '_blank')
     * @param string $rel Rel attribute value (e.g., 'noopener noreferrer')
     * @param bool $nofollow Whether to add 'nofollow' to the rel attribute
     */
    public function __construct(
        protected array $internalHosts = [],
        protected string $target = '_blank',
        protected string $rel = 'noopener noreferrer',
        protected bool $nofollow = false,
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.link', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Link) {
                return;
            }

            $destination = $node->getDestination();
            if ($destination === null) {
                return;
            }

            if (!$this->isExternalUrl($destination)) {
                return;
            }

            $node->setAttribute('target', $this->target);

            $rel = $this->rel;
            if ($this->nofollow && !str_contains($rel, 'nofollow')) {
                $rel .= ' nofollow';
            }
            $node->setAttribute('rel', trim($rel));
        });
    }

    protected function isExternalUrl(string $url): bool
    {
        // Only http:// and https:// URLs are considered for external handling
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        // Extract host from URL
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return false;
        }

        // Check against internal hosts
        $host = strtolower($host);
        foreach ($this->internalHosts as $internalHost) {
            if (strtolower($internalHost) === $host) {
                return false;
            }
        }

        return true;
    }
}
