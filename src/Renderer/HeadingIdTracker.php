<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Node\Block\Heading;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Shared service for generating and deduplicating heading IDs
 *
 * Used by HtmlRenderer, TableOfContentsExtension, and HeadingPermalinksExtension
 * to ensure consistent heading IDs across all consumers.
 *
 * Uses spl_object_id caching so the same heading node always returns the same ID
 * regardless of how many times it's queried.
 */
class HeadingIdTracker
{
    /**
     * Tracks used IDs for deduplication
     *
     * @var array<string, int>
     */
    protected array $usedIds = [];

    /**
     * Counter for auto-generated section IDs (when heading has no text)
     */
    protected int $sectionCounter = 0;

    /**
     * Cache of resolved IDs per heading node (keyed by spl_object_id)
     *
     * @var array<int, string>
     */
    protected array $resolvedIds = [];

    /**
     * Cache of plain text per node (keyed by spl_object_id)
     *
     * Caching ensures the first caller captures the original text before
     * any extensions modify the node tree (e.g., HeadingPermalinksExtension
     * appending a permalink symbol).
     *
     * @var array<int, string>
     */
    protected array $resolvedTexts = [];

    /**
     * Get the unique ID for a heading node
     *
     * Returns a cached result if this heading has already been resolved.
     * Otherwise generates, deduplicates, and caches the ID.
     */
    public function getIdForHeading(Heading $node): string
    {
        $objectId = spl_object_id($node);
        if (isset($this->resolvedIds[$objectId])) {
            return $this->resolvedIds[$objectId];
        }

        $id = $this->generateId($node);
        $this->resolvedIds[$objectId] = $id;

        return $id;
    }

    /**
     * Track an explicit ID from a non-heading element
     *
     * This prevents auto-generated heading IDs from conflicting
     * with explicitly set IDs on other elements.
     */
    public function trackId(string $id): void
    {
        if ($id !== '' && !isset($this->usedIds[$id])) {
            $this->usedIds[$id] = 0;
        }
    }

    /**
     * Normalize text into a valid CSS identifier string
     *
     * 1. Strip # characters entirely
     * 2. Trim whitespace
     * 3. Replace whitespace sequences (including Unicode spaces) with single dashes
     * 4. Replace any remaining characters that are invalid in CSS identifiers
     *    (anything other than Unicode letters/numbers, hyphens, and underscores)
     *    with dashes
     * 5. Collapse consecutive dashes and trim leading/trailing dashes
     * 6. Prefix with 'h-' if the result starts with a digit, ensuring a valid
     *    CSS ident start (digits are not allowed as the first character)
     *
     * Producing a valid CSS identifier ensures that consumers such as HTMX,
     * which call `querySelector` with the section ID for scroll-restoration,
     * do not throw a SyntaxError when headings contain inline code or special
     * characters (e.g. `$this->t($key, $params = [], $fallback = '')`).
     */
    public function normalizeId(string $text): string
    {
        $id = str_replace('#', '', $text);
        $id = trim($id);
        $id = preg_replace('/\s+/u', '-', $id) ?? $id;
        $id = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $id) ?? $id;
        $id = preg_replace('/-{2,}/', '-', $id) ?? $id;
        $id = trim($id, '-');

        if ($id !== '' && preg_match('/^\p{N}/u', $id)) {
            $id = 'h-' . $id;
        }

        return $id !== '' ? $id : 'heading';
    }

    /**
     * Get plain text content of a node
     *
     * For Heading nodes, the result is cached by spl_object_id so that
     * the original text is preserved even if extensions later modify
     * the heading's children (e.g., appending a permalink symbol).
     */
    public function getPlainText(Node $node): string
    {
        if ($node instanceof Heading) {
            $objectId = spl_object_id($node);
            if (isset($this->resolvedTexts[$objectId])) {
                return $this->resolvedTexts[$objectId];
            }

            $text = $this->extractPlainText($node);
            $this->resolvedTexts[$objectId] = $text;

            return $text;
        }

        return $this->extractPlainText($node);
    }

    /**
     * Recursively extract plain text from a node tree
     *
     * When $forId is true, non-textual elements that the djot spec excludes
     * from auto-generated heading identifiers are skipped: symbols (`:name:`)
     * and footnote references (`[^label]`). See jgm/djot#393. Otherwise the
     * full human-readable text is returned (e.g. for TOC labels), with
     * symbols rendered as `:name:`.
     */
    protected function extractPlainText(Node $node, bool $forId = false): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof FootnoteRef) {
                continue;
            }

            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math) {
                $text .= $child->getContent();
            } elseif ($child instanceof Symbol) {
                if (!$forId) {
                    $text .= ':' . $child->getName() . ':';
                }
            } elseif ($child instanceof Node) {
                $text .= $this->extractPlainText($child, $forId);
            }
        }

        return $text;
    }

    /**
     * Reset all state (called per render)
     */
    public function reset(): void
    {
        $this->usedIds = [];
        $this->sectionCounter = 0;
        $this->resolvedIds = [];
        $this->resolvedTexts = [];
    }

    /**
     * Generate and deduplicate an ID for a heading
     */
    protected function generateId(Heading $node): string
    {
        // If heading has explicit id attribute, use it
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = $idAttr ?? '';
            // Track explicit IDs so auto-generated IDs don't conflict
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 0;
            }

            return $id;
        }

        // Warm the plain-text cache so display consumers (TOC, permalinks)
        // still see the pre-mutation text including symbols.
        $this->getPlainText($node);

        // The identifier itself is formed from the plain text content
        // excluding non-textual elements such as symbols and footnote
        // references (jgm/djot#393).
        $idText = $this->extractPlainText($node, forId: true);

        if ($idText === '') {
            // Generate fallback ID
            $this->sectionCounter++;

            return 's-' . $this->sectionCounter;
        }

        $baseId = $this->normalizeId($idText);

        // Track and deduplicate
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 0;

            return $baseId;
        }

        // Already used, add suffix (first conflict is -1, second is -2, etc.)
        $this->usedIds[$baseId]++;

        return $baseId . '-' . $this->usedIds[$baseId];
    }
}
