<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Document;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Renderer\HtmlRenderer;
use Random\RandomException;

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
 *
 * Note: This extension only works with HtmlRenderer. For non-HTML renderers,
 * [[Heading Text]] syntax will be rendered as literal text.
 */
class HeadingReferenceExtension implements ResettableExtensionInterface, BeforeRenderExtensionInterface
{
    protected bool $roundTripMode = false;

    protected string $placeholderPrefix = '';

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
        // Only works with HTML output - requires heading ID tracking
        if (!$converter->getRenderer() instanceof HtmlRenderer) {
            return;
        }

        $renderer = $converter->getRenderer();
        $this->roundTripMode = $renderer->isRoundTripMode();

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
                $placeholder = $this->generatePlaceholder();
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
        // Only clear heading state here. Placeholders are created during parse()
        // but consumed by output transformers which run AFTER render(). Since
        // clear() is called at the start of render(), clearing placeholders here
        // would break reference resolution. Placeholders are reset at the end of
        // resolveRenderedReferences() instead.
        $this->headingTargets = [];
        $this->headingTargetCounts = [];
    }

    public function beforeRender(Document $document): Document
    {
        $this->rebuildPlaceholdersForDocument($document);

        return $document;
    }

    protected function generatePlaceholder(): string
    {
        if ($this->placeholderPrefix === '') {
            $this->placeholderPrefix = $this->generatePlaceholderPrefix();
        }

        return $this->placeholderPrefix . $this->placeholderCounter++ . '__';
    }

    protected function generatePlaceholderPrefix(): string
    {
        try {
            return '__djot_heading_ref_' . bin2hex(random_bytes(8)) . '_';
        } catch (RandomException) {
            return '__djot_heading_ref_' . uniqid('', true) . '_';
        }
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

    /**
     * Mirror HtmlRenderer::escape() so replacement regexes match rendered output.
     */
    protected function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    /**
     * Mirror HtmlRenderer::escapeAttribute() so replacement regexes match rendered output.
     */
    protected function escapeAttribute(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    protected function resolveRenderedReferences(string $html): string
    {
        foreach ($this->placeholders as $placeholder => $data) {
            $target = $data['target'];
            $displayText = $data['displayText'];
            $normalizedTarget = $this->normalizeQuotes($target);
            $count = $this->headingTargetCounts[$normalizedTarget] ?? 0;
            $quotedPlaceholder = preg_quote($this->escapeAttribute($placeholder), '/');
            $quotedTarget = preg_quote($this->escapeAttribute($target), '/');
            $quotedDisplayText = preg_quote($this->escape($displayText), '/');

            if ($count === 1 && isset($this->headingTargets[$normalizedTarget])) {
                $html = (string)preg_replace_callback(
                    '/<a\b(?=[^>]*\bhref="' . $quotedPlaceholder . '")(?=[^>]*\bdata-heading-ref="' . $quotedTarget . '")(?P<before>[^>]*)\bhref="' . $quotedPlaceholder . '"(?P<after>[^>]*)>'
                    . $quotedDisplayText
                    . '<\/a>/u',
                    function (array $matches) use ($target, $displayText, $normalizedTarget): string {
                        $before = $this->stripInternalHeadingReferenceAttribute($matches['before']);
                        $after = $this->stripInternalHeadingReferenceAttribute($matches['after']);

                        if ($this->roundTripMode) {
                            $after .= ' data-djot-heading-ref="' . $this->escapeAttribute($target) . '"';
                            if ($displayText !== $target) {
                                $after .= ' data-djot-heading-ref-display="'
                                    . $this->escapeAttribute($displayText) . '"';
                            }
                        }

                        return '<a'
                            . $before
                            . 'href="#' . $this->escapeAttribute($this->headingTargets[$normalizedTarget]) . '"'
                            . $after
                            . '>'
                            . $this->escape($displayText)
                            . '</a>';
                    },
                    $html,
                    1,
                );

                continue;
            }

            // Fallback: replace link with literal [[target]] or [[target|text]] syntax
            $fallback = $target === $displayText
                ? '[[' . $target . ']]'
                : '[[' . $target . '|' . $displayText . ']]';

            $pattern = '/<a\b[^>]*href="'
                . $quotedPlaceholder
                . '"(?=[^>]*\bdata-heading-ref="' . $quotedTarget . '")[^>]*>'
                . $quotedDisplayText
                . '<\/a>/u';
            $html = (string)preg_replace(
                $pattern,
                $this->escape($fallback),
                $html,
                1,
            );
        }

        $this->placeholders = [];
        $this->placeholderCounter = 0;
        $this->placeholderPrefix = '';

        return $html;
    }

    protected function rebuildPlaceholdersForDocument(Node $node): void
    {
        $this->placeholders = [];

        if ($this->placeholderPrefix === '') {
            return;
        }

        $this->collectPlaceholders($node);
    }

    protected function collectPlaceholders(Node $node): void
    {
        if ($node instanceof Link) {
            $placeholder = $node->getDestination();
            $target = $node->getAttribute('data-heading-ref');

            if (
                $placeholder !== null
                && str_starts_with($placeholder, $this->placeholderPrefix)
                && $target !== null
            ) {
                $displayText = '';
                foreach ($node->getChildren() as $child) {
                    if ($child instanceof Text) {
                        $displayText .= $child->getContent();
                    }
                }

                if ($displayText === '') {
                    $displayText = $target;
                }

                $this->placeholders[$placeholder] = [
                    'target' => $target,
                    'displayText' => $displayText,
                ];
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectPlaceholders($child);
        }
    }

    protected function stripInternalHeadingReferenceAttribute(string $attrs): string
    {
        return (string)preg_replace('/\sdata-heading-ref="[^"]*"/u', '', $attrs);
    }
}
