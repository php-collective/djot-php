<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

/**
 * Resolves [[Heading Text]] references to headings in the current document.
 *
 * Supports custom display text: [[Heading Text|click here]]
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
     * @var array<string, array{target: string, displayText: string}>
     */
    protected array $placeholders = [];

    protected int $placeholderCounter = 0;

    public function __construct(protected string $cssClass = 'heading-ref')
    {
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

            // Normalize quotes so headings with smart quotes can be matched
            // by references using straight quotes
            $normalizedText = $this->normalizeQuotes($text);

            $id = $tracker->getIdForHeading($node);
            $this->headingTargetCounts[$normalizedText] = ($this->headingTargetCounts[$normalizedText] ?? 0) + 1;

            if (!isset($this->headingTargets[$normalizedText])) {
                $this->headingTargets[$normalizedText] = $id;
            }
        });

        // Pattern: [[Heading Text]] or [[Heading Text|Display Text]]
        $inlineParser->addInlinePattern(
            '/\[\[([^\]|#][^\]|]*)(?:\|([^\]]+))?\]\]/',
            function (string $match, array $groups): Link {
                $target = trim($groups[1]);
                $displayText = isset($groups[2]) ? trim($groups[2]) : $target;
                $placeholder = '__djot_heading_ref_' . $this->placeholderCounter++ . '__';
                $this->placeholders[$placeholder] = [
                    'target' => $target,
                    'displayText' => $displayText,
                ];

                $link = new Link($placeholder);
                $link->appendChild(new Text($displayText));
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

    /**
     * Normalize quotes for comparison.
     *
     * The parser converts straight quotes to smart quotes in heading text,
     * but reference targets keep the original straight quotes. This method
     * normalizes both to straight quotes for reliable matching.
     */
    protected function normalizeQuotes(string $text): string
    {
        return str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $text,
        );
    }

    protected function resolveRenderedReferences(string $html): string
    {
        foreach ($this->placeholders as $placeholder => $data) {
            $target = $data['target'];
            $displayText = $data['displayText'];
            $normalizedTarget = $this->normalizeQuotes($target);
            $count = $this->headingTargetCounts[$normalizedTarget] ?? 0;

            if ($count === 1 && isset($this->headingTargets[$normalizedTarget])) {
                $html = str_replace(
                    'href="' . htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                    'href="#' . htmlspecialchars($this->headingTargets[$normalizedTarget], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                    $html,
                );

                continue;
            }

            // Fallback: replace link with literal [[target]] or [[target|text]] syntax
            $fallback = $target === $displayText
                ? '[[' . $target . ']]'
                : '[[' . $target . '|' . $displayText . ']]';

            $pattern = '/<a\b[^>]*href="'
                . preg_quote(htmlspecialchars($placeholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/')
                . '"[^>]*>'
                . preg_quote(htmlspecialchars($displayText, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/')
                . '<\/a>/u';
            $html = (string)preg_replace(
                $pattern,
                htmlspecialchars($fallback, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $html,
                1,
            );
        }

        $this->placeholders = [];
        $this->placeholderCounter = 0;

        return $html;
    }
}
