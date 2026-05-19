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
     * Normalize heading text into an identifier (jgm/djot#393)
     *
     * Each maximal run of non-alphanumeric ASCII characters is replaced with
     * a single `-`, and leading/trailing `-` are trimmed. Non-ASCII
     * characters (Unicode letters, digits, punctuation, symbols) are
     * preserved verbatim — they fall outside the spec's ASCII replacement
     * set and are valid CSS identifier code points.
     *
     * Two deliberate, documented deviations keep the result a valid CSS
     * identifier for `querySelector()` / HTMX consumers:
     *  - a leading ASCII digit gets an `h-` prefix (a CSS identifier cannot
     *    start with a digit);
     *  - an empty result is returned as `''` so the caller can fall back to
     *    a generated `s-N` identifier (matching djot.js), rather than a
     *    literal sentinel.
     *
     * @return string The identifier, or '' when the text has no usable content.
     */
    public function normalizeId(string $text): string
    {
        // 0x30-0x39 = 0-9, 0x41-0x5A = A-Z, 0x61-0x7A = a-z. Every other
        // byte in 0x00-0x7F is non-alphanumeric ASCII; bytes >= 0x80 (all
        // UTF-8 multibyte sequences) are left untouched so non-ASCII text is
        // preserved. No /u flag: the class only ever matches single ASCII
        // bytes, never a continuation byte of a multibyte character.
        $id = preg_replace('/[\x00-\x2F\x3A-\x40\x5B-\x60\x7B-\x7F]+/', '-', $text) ?? $text;
        $id = trim($id, '-');

        if ($id === '') {
            return '';
        }

        if (preg_match('/^[0-9]/', $id) === 1) {
            $id = 'h-' . $id;
        }

        return $id;
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
        $baseId = $this->normalizeId($idText);

        if ($baseId === '') {
            // No usable content (empty heading, or text that is entirely
            // ASCII punctuation) — fall back to a generated `s-N`
            // identifier, matching djot.js. Skip any `s-N` already taken by
            // an explicit id or a heading whose text normalizes to it, so
            // the fallback never produces a duplicate.
            do {
                $this->sectionCounter++;
                $baseId = 's-' . $this->sectionCounter;
            } while (isset($this->usedIds[$baseId]));

            $this->usedIds[$baseId] = 0;

            return $baseId;
        }

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
