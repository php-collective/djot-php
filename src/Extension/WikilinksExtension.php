<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

/**
 * Parses [[wikilinks]] into navigational links
 *
 * Converts `[[Page Name]]` and `[[page|Display Text]]` patterns into
 * clickable links, commonly used in wiki systems and note-taking apps
 * like Obsidian, Notion, and MediaWiki.
 *
 * Basic usage:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new WikilinksExtension());
 *
 * $html = $converter->convert('See [[Tigers]] for more info.');
 * // Output: <p>See <a href="tigers" class="wikilink">Tigers</a> for more info.</p>
 * ```
 *
 * With custom URL generation:
 * ```php
 * $converter->addExtension(new WikilinksExtension(
 *     urlGenerator: fn(string $page) => '/wiki/' . strtolower(str_replace(' ', '-', $page)) . '.html',
 * ));
 *
 * $html = $converter->convert('See [[Tiger Facts]]');
 * // Output: <p>See <a href="/wiki/tiger-facts.html" class="wikilink">Tiger Facts</a></p>
 * ```
 *
 * With display text:
 * ```php
 * $html = $converter->convert('Learn about [[tigers|big cats]]');
 * // Output: <p>Learn about <a href="tigers" class="wikilink">big cats</a></p>
 * ```
 *
 * Supports anchors:
 * ```php
 * $html = $converter->convert('See [[page#section]]');
 * // Output: <p>See <a href="page#section" class="wikilink">page</a></p>
 * ```
 *
 * @see https://github.com/jgm/djot/issues/26 Upstream discussion
 */
class WikilinksExtension implements ExtensionInterface
{
    /**
     * @param \Closure|null $urlGenerator Custom URL generator function. Receives page name, returns URL.
     *                                   Default: returns slugified page name
     * @param string $cssClass CSS class(es) to add to wikilink anchors
     * @param bool $newWindow Whether to open links in new window/tab
     */
    public function __construct(
        protected ?Closure $urlGenerator = null,
        protected string $cssClass = 'wikilink',
        protected bool $newWindow = false,
    ) {
        $this->urlGenerator ??= $this->defaultUrlGenerator();
    }

    public function register(DjotConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();

        // Pattern: [[page]] or [[page|display text]]
        // Supports: [[Page Name]], [[folder/page]], [[page#anchor]], [[page|text]]
        $pattern = '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/';

        // Capture urlGenerator in local variable for closure
        $urlGenerator = $this->urlGenerator ?? $this->defaultUrlGenerator();

        $inlineParser->addInlinePattern(
            $pattern,
            function (string $match, array $groups) use ($urlGenerator): Link {
                $page = trim($groups[1]);
                $displayText = isset($groups[2]) ? trim($groups[2]) : null;

                // Extract anchor if present
                $anchor = '';
                if (str_contains($page, '#')) {
                    [$page, $anchor] = explode('#', $page, 2);
                    $anchor = '#' . $anchor;
                }

                // Generate URL
                $url = $urlGenerator($page) . $anchor;

                // Determine display text
                if ($displayText === null) {
                    $displayText = $page ?: $anchor;
                }

                $link = new Link($url);
                $link->setAttribute('data-wikilink', $page);
                $link->appendChild(new Text($displayText));

                // Apply CSS classes
                foreach (explode(' ', $this->cssClass) as $class) {
                    if ($class !== '') {
                        $link->addClass($class);
                    }
                }

                // New window handling
                if ($this->newWindow) {
                    $link->setAttribute('target', '_blank');
                    $link->setAttribute('rel', 'noopener');
                }

                return $link;
            },
        );
    }

    /**
     * Default URL generator - creates URL-safe slugs
     */
    protected function defaultUrlGenerator(): Closure
    {
        return static function (string $page): string {
            // Convert to lowercase, replace spaces with hyphens
            $slug = strtolower(trim($page));
            $slug = (string)preg_replace('/\s+/', '-', $slug);
            // Remove unsafe characters, keep alphanumeric, hyphens, underscores, slashes
            $slug = (string)preg_replace('/[^a-z0-9\-_\/]/', '', $slug);
            // Remove multiple consecutive hyphens
            $slug = (string)preg_replace('/-+/', '-', $slug);

            return $slug;
        };
    }
}
