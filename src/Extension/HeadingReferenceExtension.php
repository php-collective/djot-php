<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use Djot\Renderer\HeadingIdTracker;

/**
 * Resolves [[Heading Text]] references to headings in the current document.
 *
 * This extension is intentionally limited to intra-document heading references.
 * It resolves links by heading text, not by author-guessed generated IDs, so
 * authors do not need to depend on the renderer's slug generation rules.
 *
 * Because it uses the same [[...]] syntax as WikilinksExtension, the two
 * extensions cannot be used together on the same converter instance.
 */
class HeadingReferenceExtension implements ExtensionInterface
{
    /**
     * @var array<string, string>
     */
    protected array $headingTargets = [];

    /**
     * @var array<string, int>
     */
    protected array $headingTargetCounts = [];

    /**
     * @var array<string, string>
     */
    protected array $placeholders = [];

    protected int $placeholderCounter = 0;

    public function __construct(
        protected string $cssClass = 'heading-ref',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();
        $tracker = $converter->getHeadingIdTracker();

        $converter->on('render.heading', function (RenderEvent $event) use ($tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            $text = trim($tracker->getPlainText($node));
            if ($text === '') {
                return;
            }

            $id = $tracker->getIdForHeading($node);
            $this->headingTargetCounts[$text] = ($this->headingTargetCounts[$text] ?? 0) + 1;

            if (!isset($this->headingTargets[$text])) {
                $this->headingTargets[$text] = $id;
            }
        });

        $inlineParser->addInlinePattern(
            '/\[\[([^\]|#][^\]|]*)\]\]/',
            function (string $match, array $groups) : Link {
                $target = trim($groups[1]);
                $placeholder = '__djot_heading_ref_' . $this->placeholderCounter++ . '__';
                $this->placeholders[$placeholder] = $target;

                $link = new Link($placeholder);
                $link->appendChild(new Text($target));
                foreach (explode(' ', $this->cssClass) as $class) {
                    if ($class !== '') {
                        $link->addClass($class);
                    }
                }
                $link->setAttribute('data-heading-ref', $target);

                return $link;
            },
        );

        $converter->addOutputTransformer(function (string $html): string {
            return $this->resolveRenderedReferences($html);
        });
    }

    public function clear(): void
    {
        $this->headingTargets = [];
        $this->headingTargetCounts = [];
    }

    protected function resolveRenderedReferences(string $html): string
    {
        foreach ($this->placeholders as $placeholder => $target) {
            $count = $this->headingTargetCounts[$target] ?? 0;
            if ($count === 1 && isset($this->headingTargets[$target])) {
                $html = str_replace(
                    'href="' . htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                    'href="#' . htmlspecialchars($this->headingTargets[$target], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                    $html,
                );

                continue;
            }

            $pattern = '/<a\b[^>]*href="'
                . preg_quote(htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/')
                . '"[^>]*>'
                . preg_quote(htmlspecialchars($target, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/')
                . '<\/a>/u';
            $html = (string) preg_replace(
                $pattern,
                htmlspecialchars('[[' . $target . ']]', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $html,
                1,
            );
        }

        $this->placeholders = [];
        $this->placeholderCounter = 0;

        return $html;
    }
}
